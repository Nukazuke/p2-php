<?php
/* vim: set fileencoding=cp932 ai et ts=4 sw=4 sts=4 fdm=marker: */
/* mi: charset=Shift_JIS */
/**
 * 2chからGoogle APIを使って検索し、p2で読むためのリンクに変換する
 *
 * 予定：
 * ・1日1,000回までの制限があるので検索結果をキャッシュして再問い合わせを防ぐ。
 * ・ログにその日の検索回数を記録するようにする。
 *
 * 参考にしたところ：
 * ・http://www.itmedia.co.jp/enterprise/0405/28/epn04_3.html
 * ・まるごとPHP! (Vol.1) 月宮さんの記事「PHPで簡単 SOAPサービス」
 *
 * 必要なもの：
 * ・Google アカウント
 * ・Google Web APIs のページから入手できる Developer’s Kit に含まれるWSDLファイル
 * ・PHPのmbstring機能拡張
 * ・PHP4ならPEAR::SOAP、PHP5ならSOAP拡張機能
 * ・PEAR::Pager (2.x)
 * ・PEAR::Var_Dump (1.x)
 */

// {{{ p2基本設定読み込み&認証

require_once 'conf/conf.inc.php';

$_login->authorize();

// }}}

if ($_conf['expack.google.enabled'] == 0) {
    exit('<html><body><p>Google検索は無効です。<br>conf/conf_admin_ex.inc.php の設定を変えてください。</p></body></html>');
}

if ($_conf['view_forced_by_query']) {
    if (!$_conf['ktai']) {
        output_add_rewrite_var('b', 'pc');
    } else {
        output_add_rewrite_var('b', 'k');
    }
}

// {{{ Init

// ライブラリ読み込み
require_once P2EX_LIB_DIR . '/google/search.class.php';
require_once P2EX_LIB_DIR . '/google/converter.class.php';
require_once P2EX_LIB_DIR . '/google/renderer.class.php';

// Google Search WSDLファイルのパス
$wsdl = $_conf['expack.google.wsdl'];

// Google Web APIs のライセンスキー
$key = $_conf['expack.google.key'];

// 1ページ当たりの表示件数 (Max:10)
$perPage = 10;

// 検索文字列
if (isset($_GET['word'])) {
    $_GET['q'] = $_GET['word'];
    unset($_GET['word']);
}
if (isset($_GET['q'])) {
    $q = mb_convert_encoding($_GET['q'], 'UTF-8', 'SJIS-win');
    $word = htmlspecialchars($_GET['q'], ENT_QUOTES);
} else {
    $word = $q = '';
}

// ページ番号
$p = isset($_GET['p']) ? max((int)$_GET['p'], 1) : 1;
$start = ($p - 1) * $perPage;

// 出力用変数
$totalItems = 0;
$result = NULL;
$popups = NULL;

// }}}
// {{{ Search

if (!empty($q)) {
    // 検索文字列を2ch内検索用に変換
    //$q = trim(preg_replace('/( |　)\w+:.*( |　)/u', '', $q));
    //$q .= ' site:2ch.net -site:www.2ch.net -site:info.2ch.net -site:find.2ch.net -site:p2.2ch.net';
    $q .= ' site:2ch.net';

    // Google検索クラスのインスタンスを生成する
    $google = &GoogleSearch::factory($wsdl, $key);

    // インスタンス生成に失敗
    if (PEAR::isError($google)) {
        $result = '<b>Error: ' . $google->getMessage() . '</b>';
    // インスタンス生成に成功
    } else {
        $resultObj = &$google->doSearch($q, $perPage, $start);
        // エラー発生
        if (PEAR::isError($resultObj)) {
            $result = '<b>Error: ' . $resultObj->getMessage() . '</b>';
            if (!empty($resultObj->userinfo)) {
                require_once 'Var_Dump.php';
                $result .= Var_Dump::display($resultObj->getUserInfo(), TRUE, 'HTML4_Table');
            }
        // リクエスト成功
        } else {
            $totalItems = $resultObj->estimatedTotalResultsCount;
            // ヒットあり
            if ($totalItems > 0) {
                $converter = &new Google_Converter;
                $result = array();
                $popups = array();
                $id = 1;
                foreach ($resultObj->resultElements as $obj) {
                    $result[$id] = $converter->toOutputValue($obj);
                    $popups[$id] = $converter->toPopUpValue($obj);
                    $id++;
                }
            // ヒット数ゼロ
            } else {
                $result = '&quot;' . $word . '&quot; Not Found.';
                // 検索結果の最後のページを表示しようとしたとき、
                // ヒット数がブレて$startより小さくなり、結果として0件となることがある
                if ($start > 0) {
                    $result .= '<br><a href="javascript:history.back();">Back</a>';
                }
            }
        } // end of リクエスト成功
    } // end of インスタンス生成に成功

}

// }}}
// {{{ Display

$renderer = &new Google_Renderer;

$search_element_type = 'text';
$search_element_extra_attributes = '';
if ($_conf['input_type_search']) {
    $search_element_type = 'search';
    $search_element_extra_attributes = " autosave=\"rep2.expack.search.google\" results=\"{$_conf['expack.google.recent2_num']}\" placeholder=\"Google\"";
}

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html lang="ja">
<head>
    <?php echo $_conf['meta_charset_ht']; ?>
    <meta http-equiv="Content-Style-Type" content="text/css">
    <meta http-equiv="Content-Script-Type" content="text/javascript">
    <meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
    <title>2ch検索 by Google : <?php echo $word; ?></title>
    <link rel="stylesheet" href="css.php?css=style&amp;skin=<?php echo $skin_en; ?>" type="text/css">
    <link rel="stylesheet" href="css.php?css=read&amp;skin=<?php echo $skin_en; ?>" type="text/css">
    <link rel="stylesheet" href="css.php?css=subject&amp;skin=<?php echo $skin_en; ?>" type="text/css">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <script type="text/javascript" src="js/basic.js?<?php echo $_conf['p2expack']; ?>"></script>
    <script type="text/javascript" src="js/gpopup.js?<?php echo $_conf['p2expack']; ?>"></script>
</head>
<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">
<table id="sbtoolbar1" class="toolbar" cellspacing="0"><tr><td align="left">
    <span class="itatitle"><a class="aitatitle" href="<?php echo $_SERVER['SCRIPT_NAME']; ?>"><b>2ch検索 by Google</b></a></span>
    <form action="<?php echo $_SERVER['SCRIPT_NAME']; ?>" method="get" accept-charset="<?php echo $_conf['accept_charset']; ?>" style="display:inline;">
        <input type="<?php echo $search_element_type; ?>" name="q" value="<?php echo $word; ?>"<?php echo $search_element_extra_attributes; ?>>
        <input type="submit" value="検索">
    </form>
</td></tr></table>
<?php $renderer->printSearchResult($result, $word, $perPage, $start, $totalItems); ?>
<?php $renderer->printPager($perPage, $totalItems); ?>
<?php $renderer->printPopup($popups); ?>
</body>
</html>
