<?php

use Bitrix\Main\ArgumentException;
use Bitrix\Main\FileTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Location\LocationTable;
use Likee\Site\Helpers\HL;
use Qsoft\Helpers\ComponentHelper;
use Qsoft\Logger\Logger;

class QsoftFeed extends ComponentHelper
{
    /** @var $logger Logger */
    private $logger;
    private $feed_settings;
    private $feed_filter;
    private $feed_products;
    private $feed_offers;
    private $feed_items;
    private $defaultStoresType;
    private $feed_iblock;
    private $item_props = [
        'BRAND',
        'ARTICLE',
        'TYPEPRODUCT'
    ];
    private $LockFilePath;
    private $XmlFilePath;
    private $return_array;

    public function onPrepareComponentParams($arParams)
    {
        parent::onPrepareComponentParams($arParams);
        return $arParams;
    }

    /**
     * @return array|bool|mixed
     * @throws Exception
     */
    public function executeComponent()
    {
        $this->defineLockFilePath();
        $this->logger = new Logger('FeedLog.txt');
        if (!$this->checkLockFile()) {
            try {
                $start = date('d.m.Y H:i:s');
                $this->createLockFile();
                $this->getXmlFilePath();
                $this->getIBlock();
                $this->getFeedSettings();
                $arReplace = [
                    '#NAME#' => $this->feed_settings['NAME'],
                    '#CATEGORY#' => $this->getTemplateName(),
                    '#FILE#' => $this->arParams['FEED_SETTINGS_CODE'],
                ];
                $this->logger->addSavedMessage(Loc::getMessage("HEADER_LOG", $arReplace), 'COMMON');
                $this->logger->addSavedMessage(Loc::getMessage("START_EXPORT_FEEDS", ['#DATE#' => $start]), 'COMMON');
                $this->setLocationCode();
                $this->getFeedFilter();
                $this->loadProductsByFilter();
                $this->loadOffersByFilter();
                $this->getDefaultStoresType();
                $this->getResultItems();
                $this->writeFeedToFile();
                $this->deleteLockFile();
                $this->logger->addSavedMessage(Loc::getMessage("END_EXPORT_FEEDS", ['#DATE#' => date('d.m.Y H:i:s')]), 'COMMON');
                $this->logger->pasteSeparator();
                $this->logger->writeSavedMessagesIntoFile();
                $this->logger->pasteSeparator();
                return $this->return_array;
            } catch (Exception $e) {
                $this->logger->addSavedMessage($this->logger->getExceptionInfo($e), 'COMMON');
                $this->defineLockFilePath();
                $this->deleteLockFile();
                $this->return_array['FAILED'];
                $this->return_array['ERRORS'] = $e->getMessage();
                $this->logger->addSavedMessage(Loc::getMessage("END_EXPORT_FEEDS", ['#DATE#' => date('d.m.Y H:i:s')]), 'COMMON');
                $this->logger->pasteSeparator();
                $this->logger->writeSavedMessagesIntoFile();
                $this->logger->pasteSeparator();
                return $this->return_array;
            }
        }
        return false;
    }

    private function defineLockFilePath(): void
    {
        $this->LockFilePath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/catalog_export/feed.lock';
    }

    /**
     * @throws Exception
     */
    private function createLockFile(): void
    {
        $str_start = date('d.m.Y H:i:s');
        file_put_contents($this->LockFilePath, $str_start);
        if (!$this->checkLockFile()) {
            throw new Exception(Loc::getMessage("LOCK_FILE_NOT_FOUND"));
        }
    }

    /**
     * @throws Exception
     */
    private function getIBLock(): void
    {

        $arIBlock = CIBlock::GetList(
            [],
            ['CODE' => IBLOCK_FEEDS]
        )->Fetch();

        if (empty($arIBlock)) {
            throw new Exception(Loc::getMessage('IBLOCK_NOT_LOAD'));
        }

        $this->feed_iblock = $arIBlock;
    }

    /**
     * @throws Exception
     */
    private function getFeedSettings()
    {
        if (!empty($this->arParams['FEED_SETTINGS_CODE'])) {
            $settings = CIBlockElement::GetList(
                [],
                [
                    'IBLOCK_ID' => $this->feed_iblock['ID'],
                    'CODE' => $this->arParams['FEED_SETTINGS_CODE'],
                    'ACTIVE' => 'Y',
                ],
                false,
                false,
                [
                    'ID',
                    'IBLOCK_ID',
                    'NAME',

                    'PROPERTY_FC_UPDATE_IMPORT',
                    'PROPERTY_FC_UPDATE_TIME',
                    'PROPERTY_FC_UPDATE_PERIOD',
                    'PROPERTY_LOCATION',

                    'PROPERTY_SECTION',
                    'PROPERTY_PRICE_FROM',
                    'PROPERTY_PRICE_TO',
                    'PROPERTY_OFFERS_SIZE',
                    'PROPERTY_PRICESEGMENTID',
                    'PROPERTY_SUBTYPEPRODUCT',
                    'PROPERTY_COLLECTION',
                    'PROPERTY_UPPERMATERIAL',
                    'PROPERTY_LININGMATERIAL',
                    'PROPERTY_RHODEPRODUCT',
                    'PROPERTY_SEASON',
                    'PROPERTY_COUNTRY',
                    'PROPERTY_BRAND',

                    'PROPERTY_STORES',
                    'PROPERTY_RESERVATION',
                    'PROPERTY_DELIVERY',

                ]
            )->Fetch();

            if (empty($settings)) {
                throw new Exception(Loc::getMessage("FEED_SETTINGS_NOT_LOAD"));
            }

            $this->feed_settings = $settings;
        } else {
            throw new Exception(Loc::getMessage("NO_FEED_CODE"));
        }
    }

    private function getFeedFilter()
    {
        list($productPropertiesMap, $offersPropertiesMap) = array_values($this->getPropertiesMap());

        $filter = [
            'PRODUCT' => ['IBLOCK_ID' => IBLOCK_CATALOG, 'ACTIVE' => 'Y'],
            'OFFER' => ['IBLOCK_ID' => IBLOCK_OFFERS, 'ACTIVE' => 'Y'],
            'RESULT' => [],
        ];

        foreach ($productPropertiesMap as $property) {
            if (!empty($this->feed_settings[$property . '_VALUE'])) {
                $propertyName = ($property === 'PROPERTY_SECTION') ? 'IBLOCK_SECTION_ID' : $property;
                $filter['PRODUCT'][$propertyName] = $this->feed_settings[$property . '_VALUE'];
            }
        }

        foreach ($offersPropertiesMap as $key => $value) {
            if (!empty($this->feed_settings[$key . '_VALUE'])) {
                $filter['OFFER'][$value] = $this->feed_settings[$key . '_VALUE'];
            }
        }

        $this->getFilterSizes($filter);

        $this->getFilterSections($filter);

        $this->getFilterPrices($filter, $this->feed_settings);

        $this->getGroupFilterPriceSegment($filter);

        $this->feed_filter = $filter;
    }

    private function getPropertiesMap(): array
    {
        return [
            'PRODUCT' => [
                'PROPERTY_SECTION',
                'PROPERTY_SUBTYPEPRODUCT',
                'PROPERTY_COLLECTION',
                'PROPERTY_UPPERMATERIAL',
                'PROPERTY_LININGMATERIAL',
                'PROPERTY_RHODEPRODUCT',
                'PROPERTY_SEASON',
                'PROPERTY_COUNTRY',
                'PROPERTY_BRAND',
            ],
            'OFFER' => [
                'PROPERTY_OFFERS_SIZE' => 'PROPERTY_SIZE',
            ],
            'SETTINGS' => [
                'PROPERTY_FC_UPDATE_IMPORT',
                'PROPERTY_FC_UPDATE_TIME',
                'PROPERTY_FC_UPDATE_PERIOD',
            ]
        ];
    }

    private function getFilterSizes(array &$filter)
    {
        if (!empty($filter['OFFER']['PROPERTY_SIZE'])) {
            $filter['OFFER']['PROPERTY_SIZE_VALUE'] = $this->strToArray($filter['OFFER']['PROPERTY_SIZE']);
        }
        unset($filter['OFFER']['PROPERTY_SIZE']);
    }

    private function getFilterSections(array &$filter)
    {
        $arSections = [];
        foreach ($filter['PRODUCT']['IBLOCK_SECTION_ID'] as $sectionId) {
            $section = $this->getSectionById($sectionId);
            if (!empty($section)) {
                $relatedSections = $this->loadRelatedSections($section);
                $arSections = array_merge($arSections, $relatedSections);
            }
        }
        $filter['PRODUCT']['IBLOCK_SECTION_ID'] = array_values(array_unique($arSections));
    }

    private function getSectionById($id)
    {
        if (!$id) {
            return [];
        }
        $arSection = [];
        $res = CIBlockSection::GetList(
            [],
            [
                "IBLOCK_ID" => IBLOCK_CATALOG,
                "ID" => $id,
                "ACTIVE" => "Y",
            ],
            false,
            [
                "ID",
                "LEFT_MARGIN",
                "RIGHT_MARGIN",
            ],
            false
        );

        while ($arItem = $res->Fetch()) {
            $arSection = $arItem;
        }

        return $arSection;
    }

    private function loadRelatedSections($arSection): array
    {
        $arSectionIds = [];

        if (is_array($arSection) && array_key_exists("LEFT_MARGIN", $arSection) && array_key_exists(
            "RIGHT_MARGIN",
            $arSection
        )) {
            $res = CIBlockSection::GetList(
                [
                    "SORT" => "ASC",
                ],
                [
                    "IBLOCK_ID" => IBLOCK_CATALOG,
                    ">LEFT_MARGIN" => $arSection["LEFT_MARGIN"],
                    "<RIGHT_MARGIN" => $arSection["RIGHT_MARGIN"],
                ],
                [
                    "ID",
                ],
                false
            );

            while ($arItem = $res->Fetch()) {
                $arSectionIds[] = $arItem["ID"];
            }
        }

        return $arSectionIds;
    }

    private function getFilterPrices(array &$filter, array $data)
    {
        if (!empty($this->feed_settings['PROPERTY_PRICE_FROM_VALUE'])) {
            $filter['RESULT']['MIN_PRICE'] = intval($data['PROPERTY_PRICE_FROM_VALUE']);
        }

        if (!empty($this->feed_settings['PROPERTY_PRICE_TO_VALUE'])) {
            $filter['RESULT']['MAX_PRICE'] = intval($data['PROPERTY_PRICE_TO_VALUE']);
        }
    }

    private function getGroupFilterPriceSegment(array &$filter)
    {
        if (!empty($this->feed_settings['PROPERTY_PRICESEGMENTID_VALUE'])) {
            $filter['RESULT']['SEGMENT'] = $this->feed_settings['PROPERTY_PRICESEGMENTID_VALUE'];
        }
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws Exception
     */
    private function loadProductsByFilter()
    {
        $res = CIBlockElement::GetList(
            [],
            $this->feed_filter['PRODUCT'],
            false,
            false,
            [
                "ID",
                "IBLOCK_ID",
                "NAME",
                "PREVIEW_TEXT",
                "DETAIL_PICTURE",
                "PREVIEW_PICTURE",
                "SORT",
                "SHOW_COUNTER",
                "DETAIL_PAGE_URL",
            ]
        );
        $arProducts = $this->processProducts($res);

        if (empty($arProducts)) {
            throw new Exception(Loc::getMessage("FILTERED_PRODUCTS_NOT_FOUND"));
        }

        $this->feed_products = $arProducts;
    }

    /**
     * @param $res CDBResult
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function processProducts($res)
    {
        $arProducts = array();
        $arImageIds = array();
        // Массивы для линковки категорий и подкатегорий каталога
        $arVidTypeproduct = array();
        $arTypeproductSubtypeproduct = array();

        /** @var  $oItem  _CIBElement */
        while ($oItem = $res->getNextElement()) {
            $arItem = $oItem->getFields();
            $arItem['PROPERTIES'] = $oItem->GetProperties([], ['CODE' => $this->item_props]);

            $arProducts[$arItem["ID"]] = [
                "ID" => $arItem["ID"],
                "NAME" => $arItem['NAME'],
                "DETAIL_PICTURE" => $arItem["DETAIL_PICTURE"],
                "PREVIEW_PICTURE" => $arItem["PREVIEW_PICTURE"],
                "PROPERTY_BRAND_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['BRAND'], 'UF_NAME'),
                "PROPERTY_TYPEPRODUCT_VALUE" => HL::getFieldValueByProp(
                    $arItem ['PROPERTIES']['TYPEPRODUCT'],
                    'UF_NAME'
                ),
                "PROPERTY_MODEL_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['MODEL'], 'UF_NAME'),
                "PROPERTY_UPPERMATERIAL_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['UPPERMATERIAL'], 'UF_NAME'),
                "PROPERTY_LININGMATERIAL_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['LININGMATERIAL'], 'UF_NAME'),
                "PROPERTY_MATERIALSOLE_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['MATERIALSOLE'], 'UF_NAME'),
                "PROPERTY_COLORSFILTER_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['COLORSFILTER'], 'UF_NAME'),
                "PROPERTY_BRAND_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['BRAND'], 'UF_NAME'),
                "PROPERTY_COUNTRY_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['COUNTRY'], 'UF_NAME'),
                "PROPERTY_HEELHEIGHT_TYPE_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['HEELHEIGHT_TYPE'], 'UF_NAME'),
                "PROPERTY_RHODEPRODUCT_VALUE" => HL::getFieldValueByProp($arItem['PROPERTIES']['RHODEPRODUCT'], 'UF_NAME'),
                "PROPERTY_SIZERANGE_VALUE" => $arItem['PROPERTIES']['SIZERANGE']['VALUE'],
                "PROPERTY_VID_VALUE" => $arItem['PROPERTIES']['VID']['VALUE'],
                "PROPERTY_TYPEPRODUCT_VALUE_ID" => $arItem['PROPERTIES']['TYPEPRODUCT']['VALUE'],
                "PROPERTY_SUBTYPEPRODUCT_VALUE" => $arItem['PROPERTIES']['SUBTYPEPRODUCT']['VALUE'],
                "PROPERTY_ARTICLE_VALUE" => $arItem['PROPERTIES']['ARTICLE']['VALUE'],
                "SORT" => $arItem["SORT"],
                "SHOW_COUNTER" => $arItem["SHOW_COUNTER"],
                "DETAIL_PAGE_URL" => SITE_DIR . $arItem["CODE"],
                "IBLOCK_SECTION_ID" => $arItem["IBLOCK_SECTION_ID"],
                "MORE_PHOTO" => $arItem['PROPERTIES']['MORE_PHOTO']['VALUE']
            ];
            $arImageIds[] = $arItem["DETAIL_PICTURE"];
            if (!empty($arItem['PROPERTIES']['MORE_PHOTO']['VALUE']) && is_array($arItem['PROPERTIES']['MORE_PHOTO']['VALUE'])) {
                $arImageIds = array_merge($arImageIds, $arItem['PROPERTIES']['MORE_PHOTO']['VALUE']);
            }
            if (!empty($arItem["PREVIEW_PICTURE"])) {
                $arImageIds[] = $arItem["PREVIEW_PICTURE"];
            }
            // Заполняем информацию о категории товара в каталоге
            if (!empty($arItem['PROPERTIES']['VID']['VALUE']) && !empty($arItem ['PROPERTIES']['TYPEPRODUCT']['VALUE'])) {
                $arVidTypeproduct[$arItem['PROPERTIES']['VID']['VALUE']][$arItem ['PROPERTIES']['TYPEPRODUCT']['VALUE']] = true;
            }
            if (!empty($arItem['PROPERTIES']['TYPEPRODUCT']['VALUE']) && !empty($arItem['PROPERTIES']['SUBTYPEPRODUCT']['VALUE'])) {
                $arTypeproductSubtypeproduct[$arItem['PROPERTIES']['TYPEPRODUCT']['VALUE']][$arItem['PROPERTIES']['SUBTYPEPRODUCT']['VALUE']] = true;
            }
        }

        if (!empty($arImageIds)) {
            $res = FileTable::getList(array(
                "select" => array(
                    "ID",
                    "SUBDIR",
                    "FILE_NAME",
                ),
                "filter" => array(
                    "ID" => $arImageIds,
                ),
            ));
            $arImages = array();
            while ($arItem = $res->Fetch()) {
                $src = "/upload/" . $arItem["SUBDIR"] . "/" . $arItem["FILE_NAME"];
                if (!exif_imagetype($_SERVER["DOCUMENT_ROOT"] . $src)) {
                    continue;
                }
                $arImages[$arItem["ID"]] = $src;
            }
            foreach ($arProducts as $id => &$arItem) {
                foreach ($arItem["MORE_PHOTO"] as &$morePhotoId) {
                    if (!empty($arImages[$morePhotoId])) {
                        $morePhotoId = $arImages[$morePhotoId];
                    }
                }
                if (!empty($arImages[$arItem["DETAIL_PICTURE"]])) {
                    $arItem["DETAIL_PICTURE"] = $arImages[$arItem["DETAIL_PICTURE"]];
                } else {
                    unset($arProducts[$id]);
                    continue;
                }
                if (!empty($arImages[$arItem["PREVIEW_PICTURE"]])) {
                    $arItem["PREVIEW_PICTURE"] = $arImages[$arItem["PREVIEW_PICTURE"]];
                }
            }
        }

        // Формируем и добавляем информацию о структуре каталога в $arResult['CATEGORIES']
        $this->getCategories($arVidTypeproduct, $arTypeproductSubtypeproduct);

        return $arProducts;
    }

    /**
     * @param  array $arVidTypeproduct
     * @param  array $arTypeproductSubtypeproduct
     *
     * @return array
     */
    private function getCategories($arVidTypeproduct, $arTypeproductSubtypeproduct)
    {
        $result = array();

        $vidTitles = array_keys($arVidTypeproduct);
        $vidTitles = $this->getCategoriesTitles('Vid', $vidTitles);

        $typeproductTitles = array();
        foreach (array_values($arVidTypeproduct) as $value) {
            foreach ($value as $typeVal => $subvalue) {
                if (!empty($typeVal)) {
                    $typeproductTitles[] = $typeVal;
                }
            }
        }
        $typeproductTitles = array_merge($typeproductTitles, array_keys($arTypeproductSubtypeproduct));
        $typeproductTitles = $this->getCategoriesTitles('Typeproduct', $typeproductTitles);

        $subtypeproductTitles = array();
        foreach (array_values($arTypeproductSubtypeproduct) as $value) {
            foreach ($value as $subtypeVal => $subvalue) {
                if (!empty($subtypeVal)) {
                    $subtypeproductTitles[] = $subtypeVal;
                }
            }
        }
        $subtypeproductTitles = $this->getCategoriesTitles('Subtypeproduct', $subtypeproductTitles);

        foreach ($arVidTypeproduct as $vid => $typeproductList) {
            $result[] = [
                'id' => $this->reduceCategoryId($vid),
                'title' => $vidTitles[$vid]
            ];

            foreach ($typeproductList as $typeproduct => $value) {
                $result[] = [
                    'parentId' => $this->reduceCategoryId($vid),
                    'id' => $this->reduceCategoryId($vid . $typeproduct),
                    'title' => $typeproductTitles[$typeproduct]
                ];

                foreach ($arTypeproductSubtypeproduct[$typeproduct] as $subtypeproduct => $subvalue) {
                    $result[] = [
                        'parentId' => $this->reduceCategoryId($vid . $typeproduct),
                        'id' => $this->reduceCategoryId($vid . $typeproduct . $subtypeproduct),
                        'title' => $subtypeproductTitles[$subtypeproduct]
                    ];
                }
            }
        }

        $this->arResult['CATEGORIES'] = $result;

        return $result;
    }

    /**
     * Получает разом все названия разделов структуры каталога из HL
     *
     * @param  string $HLName
     * @param  array $categoriesList
     *
     * @return array
     */
    private function getCategoriesTitles($HLName, $categoriesList)
    {
        $result = array();
        $obEntity = HL::getEntityClassByHLName($HLName);
        if (!empty($obEntity) && is_object($obEntity)) {
            $sClass = $obEntity->getDataClass();

            $rsData = $sClass::getList([
                'select' => ['UF_NAME', 'UF_XML_ID'],
                'filter' => ['UF_XML_ID' => $categoriesList]
            ]);

            while ($entry = $rsData->fetch()) {
                $result[$entry['UF_XML_ID']] = $entry['UF_NAME'];
            }
        }
        return $result;
    }
    
    /**
     * Убирает из id категории лишние нули, чтобы влезть в лимит 20 символов у GoodsXML
     *
     * @param  string $categoryId
     *
     * @return string
     */
    private function reduceCategoryId($categoryId)
    {
        return preg_replace('/(?<=\D)(0+)(?=\d)/', '', $categoryId);
    }

    /**
     * @throws Exception
     */
    private function loadOffersByFilter()
    {
        $res = CIBlockElement::GetList(
            [
                "SORT" => "ASC",
            ],
            $this->feed_filter['OFFER'],
            false,
            false,
            [
                "ID",
                "IBLOCK_ID",
                "PROPERTY_CML2_LINK",
                "PROPERTY_SIZE",
            ]
        );

        $arOffers = $this->processOffers($res);
        $this->feed_offers = $arOffers;
    }

    /**
     * @param $res CDBResult
     * @return array
     */
    private function processOffers($res)
    {
        $arOffers = [];
        while ($arItem = $res->Fetch()) {
            if (!$this->feed_products[$arItem["PROPERTY_CML2_LINK_VALUE"]]) {
                continue;
            }
            $arOffers[$arItem["ID"]] = [
                'PROPERTY_CML2_LINK_VALUE' => $arItem['PROPERTY_CML2_LINK_VALUE'],
                'PROPERTY_SIZE_VALUE' => $arItem['PROPERTY_SIZE_VALUE'],
            ];
        }

        return $arOffers;
    }

    private function getResultItems(): array
    {
        if (!empty($this->items)) {
            return $this->items;
        }
        global $LOCATION;
        $arRests = $this->getRests();
        $items = [];
        foreach ($this->feed_offers as $offerId => $value) {
            if (!$arRests[$offerId]) {
                continue;
            }
            $pid = $value["PROPERTY_CML2_LINK_VALUE"];
            if (!$items[$pid]) {
                $items[$pid] = $this->feed_products[$pid];
            }
            $items[$pid]["SIZES"][] = $value["PROPERTY_SIZE_VALUE"];
            $items[$pid]["OFFERS"][$offerId] = $value["PROPERTY_SIZE_VALUE"];
        }
        $arPrices = $LOCATION->getProductsPrices(array_keys($items));
        foreach ($items as $key => &$item) {
            $arPrice = $arPrices[$key];
            $item['PRICE'] = $arPrice["PRICE"];
            $item['OLD_PRICE'] = $arPrice["OLD_PRICE"];
            $item['PERCENT'] = $arPrice["PERCENT"];
            $item['SEGMENT'] = $arPrice["SEGMENT"];
            if (!isset($item['PRICE'])) {
                unset($items[$key]);
                continue;
            }
            if (isset($this->feed_filter['RESULT']['MIN_PRICE'])) {
                $min_price = $this->feed_filter['RESULT']['MIN_PRICE'];
                if ($item['PRICE'] < $min_price) {
                    unset($items[$key]);
                    continue;
                }
            }
            if (isset($this->feed_filter['RESULT']['MAX_PRICE'])) {
                $max_price = $this->feed_filter['RESULT']['MAX_PRICE'];
                if ($item['PRICE'] > $max_price) {
                    unset($items[$key]);
                    continue;
                }
            }
            if (isset($this->feed_filter['RESULT']['SEGMENT']) && $arPrice['SEGMENT'] !== $this->feed_filter['RESULT']['SEGMENT']) {
                unset($items[$key]);
                continue;
            }
            if (isset($this->feed_filter['RESULT']['SEGMENT_FROM'])) {
                $from = $this->feed_filter['RESULT']['SEGMENT_FROM'];
                if ($item['PERCENT'] < $from) {
                    unset($items[$key]);
                    continue;
                }
            }
            if (isset($this->feed_filter['RESULT']['SEGMENT_TO'])) {
                $to = $this->feed_filter['RESULT']['SEGMENT_TO'];
                if ($item['PERCENT'] > $to) {
                    unset($items[$key]);
                    continue;
                }
            }
        }

        $this->feed_items = $items;
        $this->arResult['ITEMS'] = $items;

        $arReplace = [
            '#COUNT#' => count($items),
            '#ITEMS#' => $this->logger->num2word(
                count($items),
                array(
                    Loc::getMessage("ONE_ITEM"),
                    Loc::getMessage("2_3_4_ITEMS"),
                    Loc::getMessage("MORE_ITEMS")
                )
            )
        ];

        $this->logger->addSavedMessage(Loc::getMessage("EXPORTED_ITEMS_COUNT", $arReplace), 'COMMON');
        return $items;
    }

    public function getDefaultStoresType()
    {
        $filter = [];
        $this->getGroupFilterStores($filter);
        $storesType = $filter['RESULT']['STORES'];

        $this->defaultStoresType = $storesType;

        return true;
    }

    private function getGroupFilterStores(array &$filter)
    {
        if (isset($this->stores)) {
            $filter['RESULT']['STORES'] = $this->stores;
            return;
        }

        $stores = 0;
        if (!empty($this->feed_settings['PROPERTY_DELIVERY_VALUE'])) {
            $stores += 1;
        }

        if (!empty($this->feed_settings['PROPERTY_RESERVATION_VALUE'])) {
            $stores += 2;
        }

        if (!in_array($stores, [1, 2])) {
            $stores = false;
        }

        $this->stores = $stores;
        $filter['RESULT']['STORES'] = $stores;
    }

    /**
     * @throws Exception
     */
    private function deleteLockFile(): void
    {
        if (file_exists($this->LockFilePath)) {
            $str_start = file_get_contents($this->LockFilePath);
            $str_end = date('d.m.Y H:i:s');
            $str_diff = date('i:s', strtotime($str_end) - strtotime($str_start));
            unlink($this->LockFilePath);

            if ($this->checkLockFile()) {
                throw new Exception(Loc::getMessage("LOCK_FILE_NOT_DELETED"));
            }

            $this->return_array = [
                'END_TIME' => $str_end,
                'DURATION' => $str_diff,
                'STATUS' => 'SUCCESS',
                'ERRORS' => [],
            ];
        }
    }

    private function getXmlFilePath(): void
    {
        $this->XmlFilePath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/catalog_export/' . $this->arParams['FEED_SETTINGS_CODE'] . '.xml';
    }

    private function writeFeedToFile(): void
    {
        ob_clean();
        ob_start();
        $this->includeComponentTemplate();
        $RESULT = ob_get_contents();
        ob_end_clean();
        if (!empty($RESULT)) {
            file_put_contents($this->XmlFilePath, $RESULT);
        }
    }

    private function checkLockFile(): bool
    {
        return file_exists($this->LockFilePath);
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @throws Exception
     */
    private function setLocationCode(): void
    {
        global $LOCATION;

        if (!empty($this->feed_settings['PROPERTY_LOCATION_VALUE'])) {
            $res = LocationTable::GetList(array(
                "select" => array(
                    "ID",
                    "CODE",
                    "NAME_RU" => "NAME.NAME",
                ),
                "filter" => array(
                    "NAME.NAME" => $this->feed_settings['PROPERTY_LOCATION_VALUE'],
                    "NAME.LANGUAGE_ID" => "ru",
                ),
            ))->Fetch();

            if (empty($res) || empty($res['CODE'])) {
                throw new Exception(Loc::getMessage("LOCATION_NOT_SET"));
            }

            $LOCATION->code = $res['CODE'];
        }
    }

    /**
     * @param $restsType
     * @return array
     */
    private function getRests(): array
    {
        global $LOCATION;

        if (!empty($this->feed_settings['PROPERTY_STORES_VALUE'])) {
            $arRests = $LOCATION->getRests(array_keys($this->feed_offers), $this->defaultStoresType, false, false, $this->feed_settings['PROPERTY_STORES_VALUE']);
        } else {
            $arRests = $LOCATION->getRests(array_keys($this->feed_offers), $this->defaultStoresType);
        }

        return $arRests;
    }
}
