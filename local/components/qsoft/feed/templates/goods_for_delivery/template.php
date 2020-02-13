<? if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
} ?>
<?
use Bitrix\Main\Localization\Loc; ?>
<?
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<yml_catalog date="' . date('Y-m-d H:i') . '">';
echo '<shop>';
echo '<url>' . 'https://' . SITE_SERVER_NAME . '/' . '</url>';

echo '<categories>';
foreach ($arResult['CATEGORIES'] as $category) {
    echo '<category id="' . $category['id'] . '" ' . (!empty($category['parentId']) ? 'Parentid="' . $category['parentId'] . '"' : '') . '>' . htmlspecialchars($category['title'], ENT_XML1) . '</category>';
}
echo '</categories>';

echo '<offers>';
foreach ($arResult['ITEMS'] as $arItem) {
    foreach ($arItem['OFFERS'] as $offerId => $size) {
        $availability = (count($arItem['SIZES'])) ? 'true' : 'false';
    
        echo '<offer id="' . htmlspecialchars($offerId, ENT_XML1) . '" available="' . $availability . '">';
        echo '<url>' . 'https://' . SITE_SERVER_NAME . $arItem['DETAIL_PAGE_URL'] . '</url>';
        echo '<name>' . htmlspecialchars($arItem['NAME'], ENT_XML1) . '</name>';
        echo '<price>' . $arItem['PRICE'] . '</price>';
        echo '<vat>' . 'NO_VAT' . '</vat>';
    
        echo '<picture>' . 'https://' . SITE_SERVER_NAME . $arItem['DETAIL_PICTURE'] . '</picture>';
        echo '<picture>' . 'https://' . SITE_SERVER_NAME . $arItem['PREVIEW_PICTURE'] . '</picture>';
    
        foreach ($arItem['MORE_PHOTO'] as $morePhotoUrl) {
            echo '<picture>' . 'https://' . SITE_SERVER_NAME . $morePhotoUrl . '</picture>';
        }
    
        if (!empty($arItem['PROPERTY_MODEL_VALUE'])) {
            echo '<model>' . htmlspecialchars($arItem['PROPERTY_MODEL_VALUE'], ENT_XML1) . '</model>';
        }
    
        echo '<param name="Страна дизайна">' . 'Россия' . '</param>';
    
        if (!empty($arItem['PROPERTY_UPPERMATERIAL_VALUE'])) {
            echo '<param name="Материал верха">' . htmlspecialchars($arItem['PROPERTY_UPPERMATERIAL_VALUE'], ENT_XML1) . '</param>';
        }
        if (!empty($arItem['PROPERTY_LININGMATERIAL_VALUE'])) {
            echo '<param name="Материал подкладки">' . htmlspecialchars($arItem['PROPERTY_LININGMATERIAL_VALUE'], ENT_XML1) . '</param>';
        }
        if (!empty($arItem['PROPERTY_MATERIALSOLE_VALUE'])) {
            echo '<param name="Материал подошвы">' . htmlspecialchars($arItem['PROPERTY_MATERIALSOLE_VALUE'], ENT_XML1) . '</param>';
        }
        if (!empty($arItem['PROPERTY_COLORSFILTER_VALUE'])) {
            echo '<param name="Цвет">' . htmlspecialchars($arItem['PROPERTY_COLORSFILTER_VALUE'], ENT_XML1) . '</param>';
        }
        if (!empty($arItem['PROPERTY_BRAND_VALUE'])) {
            echo '<param name="Бренд">' . htmlspecialchars($arItem['PROPERTY_BRAND_VALUE'], ENT_XML1) . '</param>';
        }
        if (!empty($arItem['PROPERTY_COUNTRY_VALUE'])) {
            echo '<param name="Страна происхождения">' . htmlspecialchars($arItem['PROPERTY_COUNTRY_VALUE'], ENT_XML1) . '</param>';
        }
        if (!empty($arItem['PROPERTY_HEELHEIGHT_TYPE_VALUE'])) {
            echo '<param name="Высота каблука">' . htmlspecialchars($arItem['PROPERTY_HEELHEIGHT_TYPE_VALUE'], ENT_XML1) . '</param>';
        }

        echo '<param name="Размер">' . htmlspecialchars($size, ENT_XML1) . '</param>';
        echo '<param name="Размер производителя">' . htmlspecialchars($size, ENT_XML1) . '</param>';
        
        if (!empty($arItem['PROPERTY_RHODEPRODUCT_VALUE'])) {
            echo '<param name="Пол">' . htmlspecialchars($arItem['PROPERTY_RHODEPRODUCT_VALUE'], ENT_XML1) . '</param>';
        }
        if (!empty($arItem['PROPERTY_ARTICLE_VALUE'])) {
            echo '<param name="Артикул">' . htmlspecialchars($arItem['PROPERTY_ARTICLE_VALUE'], ENT_XML1) . '</param>';
        }
    
        $categoryId = '';
        if (!empty($arItem['PROPERTY_VID_VALUE'])) {
            $categoryId .= $arItem['PROPERTY_VID_VALUE'];
        }
        if (!empty($arItem['PROPERTY_TYPEPRODUCT_VALUE_ID'])) {
            $categoryId .= $arItem['PROPERTY_TYPEPRODUCT_VALUE_ID'];
        }
        if (!empty($arItem['PROPERTY_SUBTYPEPRODUCT_VALUE'])) {
            $categoryId .= $arItem['PROPERTY_SUBTYPEPRODUCT_VALUE'];
        }
        $categoryId = preg_replace('/(?<=\D)(0+)(?=\d)/', '', $categoryId);
        echo '<categoryId>' . $categoryId . '</categoryId>';
    
        echo '<barcode>' . '' . '</barcode>';
        echo '</offer>';
    }
}
echo '</offers>';
echo '</shop>';
echo '</yml_catalog>';
