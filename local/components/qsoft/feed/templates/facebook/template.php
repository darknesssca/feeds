<?if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) {
    die();
}?>
<?use Bitrix\Main\Localization\Loc;?>
<?
echo '<?xml version="1.0"?>';
echo '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">';
echo '<channel>';
echo '<title>' . Loc::getMessage('SITE_NAME') . '</title>';
echo '<link>' . 'https://' . SITE_SERVER_NAME . '/' . '</link>';
echo '<description>' . Loc::getMessage('SITE_DESC') . '</description>';
foreach ($arResult['ITEMS'] as $arItem) {
    echo '<item>';
    echo '<g:id>' . $arItem['PROPERTY_ARTICLE_VALUE'] . '</g:id>';
    echo '<g:title>' . $arItem['PROPERTY_TYPEPRODUCT_VALUE'] . '</g:title>';
    echo '<g:description>' . $arItem['NAME'] . '</g:description>';
    echo '<g:link>' . 'https://' . SITE_SERVER_NAME . $arItem['DETAIL_PAGE_URL'] . '</g:link>';
    echo '<g:image_link>' . 'https://' . SITE_SERVER_NAME . $arItem['DETAIL_PICTURE'] . '</g:image_link>';
    echo '<g:brand>' . $arItem['PROPERTY_BRAND_VALUE'] . '</g:brand>';
    echo '<g:condition>' . 'new' . '</g:condition>';
    $stock = (count($arItem['SIZES'])) ? 'in stock' : 'out of stock';
    echo '<g:availability>' . $stock . '</g:availability>';
    if ($arItem['SEGMENT'] == 'Red') {
        echo '<g:price>' . $arItem['PRICE'] . ' RUB' . '</g:price>';
    } elseif ($arItem['SEGMENT'] == 'White') {
        echo '<g:price>' . $arItem['OLD_PRICE'] . ' RUB' . '</g:price>';
    }
    echo '</item>';
}
echo '</channel>';
echo '</rss>';
