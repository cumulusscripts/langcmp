<?php
$header = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Comparator of language packs</title>
    <style>
        LI { list-style: none; }
        .same { background-color: sandybrown; }
        .conflict { background-color: red; }
        .new { background-color: lime; }
        .deprecated { background-color: gray; }
    </style>
</head>
<body>';
$footer = '</body></html>';
$cdata_regex = '/^<[^><]+><!\[CDATA\[(\s|.)*\]\]><\/[^><]+>$/ui';

if($_SERVER['REQUEST_METHOD'] == 'POST'):
    if( !$_FILES['old_lang_file']['size'] || $_FILES['old_lang_file']['error'] ||
        !$_FILES['new_lang_file']['size'] || $_FILES['new_lang_file']['error']):
        exit("Files didn't upload");
    endif;

    function getXmlObject($file) {
        $fileContents = file_get_contents($file);
        $strippedOfComments = preg_replace('/<\!--.*?-->/', '', $fileContents);
        return new SimpleXMLIterator($strippedOfComments);
    }

    $old = getXmlObject($_FILES['old_lang_file']['tmp_name']);
    $new = getXmlObject($_FILES['new_lang_file']['tmp_name']);

    function find_diffs(&$old, &$new, &$diffs, $deep = 0) {
        $deep++;
        global $cdata_regex;
        for( $new->rewind(); $new->valid(); $new->next() ) {
            $k = $new->key();
            $childs = $new->hasChildren();
            $parent = $new->current();
            $value = (string)$new->current();

            if(!$old || !isset($old->$k)) {
                if(!$childs) {
                    if($_POST['include_new']) {
                        $value = preg_match($cdata_regex, $parent->asXML()) ? '<![CDATA['.$_POST['new_prefix'].$value.']]>' : $_POST['new_prefix'].$value;
                        $diffs['childs'][$k] = ['name' => $k, 'class' => 'new', 'content' => $value, 'childs' => null];
                    }
                } else {
                    $diffs['childs'][$k] = ['name' => $k, 'class' => 'new', 'content' => null, 'childs' => []];
                    $gag = null;
                    find_diffs($gag, $parent, $diffs['childs'][$k]);
                    if(!$_POST['include_new'] && !count($diffs['childs'][$k]['childs'])) unset($diffs['childs'][$k]);
                }
            } else {
                for( $old->rewind(); $old->valid(); $old->next() ) if ($old->key() == $k) $old_childs = $old->hasChildren();

                if ($old_childs == $childs) $class = 'same';
                elseif(!$_POST['include_conflicts']) continue;
                else $class = 'conflict';

                if(!$childs) {
                    if($_POST['include_same']) {
                        if($_POST['prefer_old_values']) $value = preg_match($cdata_regex, $old->$k->asXML()) ? '<![CDATA['.$_POST['old_prefix'].(string)$old->$k.']]>' : $_POST['old_prefix'].(string)$old->$k;
                        else $value = preg_match($cdata_regex, $parent->asXML()) ? '<![CDATA['.$_POST['new_prefix'].$value.']]>' : $_POST['new_prefix'].$value;
                        $diffs['childs'][$k] = ['name' => $k, 'class' => $class, 'content' => $value, 'childs' => null];
                    }
                } else {
                    $diffs['childs'][$k] = ['name' => $k, 'class' => $class, 'content' => null, 'childs' => []];
                    find_diffs($old->$k, $parent, $diffs['childs'][$k]);
                    if(!$_POST['include_same'] && !count($diffs['childs'][$k]['childs'])) unset($diffs['childs'][$k]);
                }
            }
        }
        $deep--;
    }

    function find_deprecated(&$new, &$old, &$diffs, $deep = 0) {
        $deep++;
        global $cdata_regex;
        for( $old->rewind(); $old->valid(); $old->next() ) {
            $k = $old->key();

            if($old->hasChildren()) {
                $class = ($new && isset($new->$k)) ? 'same' : 'deprecated';
                if (!isset($diffs['childs'][$k])) $diffs['childs'][$k] = ['name' => $k, 'class' => $class, 'content' => null, 'childs' => []];

                $old_child = null;
                if ($new) for( $new->rewind(); $new->valid(); $new->next() ) {
                    if ($new->key() == $k) {
                        $old_child = $new->current();
                        break;
                    }
                }

                find_deprecated($old_child, $old->current(), $diffs['childs'][$k]);
                if(!count($diffs['childs'][$k]['childs'])) unset($diffs['childs'][$k]);
            } elseif(!$new || !isset($new->$k)) {
                $value = preg_match($cdata_regex, $old->current()->asXML()) ? '<![CDATA['.$_POST['deprecated_prefix'].(string)$old->current().']]>' : $_POST['deprecated_prefix'].(string)$old->current();
                $diffs['childs'][$k] = ['name' => $k, 'class' => 'deprecated', 'content' => $value, 'childs' => null];
            }
        }
        $deep--;
    }

    function build_list(&$diffs, $deep = 0) {
        $deep++;
        if($deep > 1) echo '<li>&lt;'.$diffs['name'].'&gt;</li>';
        foreach ($diffs['childs'] as $k => $value) {
            echo '<ul class="'.$value['class'].'">';
            if (is_array($value['childs'])) build_list($value, $deep);
            else echo '<li class="'.$value['class'].'">&lt;'.$k.'&gt;'.htmlspecialchars($value['content']).'&lt;/'.$k.'&gt;</li>';
            echo '</ul>';
        }
        if($deep > 1) echo '<li>&lt;/'.$diffs['name'].'&gt;</li>';
        $deep--;
    }

    function build_xml(&$diffs, &$xml, $deep = 0) {
        $deep++;
        if ($deep == 1) $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><language></language>', null, false);
        foreach ($diffs['childs'] as $k => $value)
            if (is_array($value['childs'])) build_xml($value, $xml->addChild($value['name']), $deep);
            else $xml->addChild($k, $value['content']);
        $deep--;
    }

    $diffs = ['name' => 'language', 'class' => 'old', 'content' => null, 'childs' => []];

    find_diffs($old, $new, $diffs);
    if ($_POST['include_deprecated']) find_deprecated($new, $old, $diffs);

    if($_POST['format'] == 'list') {
        echo $header;
        build_list($diffs);
        echo $footer;
    } else {
        $xml = null;
        build_xml($diffs, $xml);
        header('Content-type: text/xml');
        exit($xml->asXML());
    }

else: echo $header; ?>
    <form action="" method="post" enctype="multipart/form-data" style="display: block; width: 600px; margin: auto">
        <fieldset>
            <legend>Options</legend>
            <table border="0" width="100%">
                <tr><td>old language XML file</td><td><input type="file" name="old_lang_file" required /></td></tr>
                <tr><td>new language XML file</td><td><input type="file" name="new_lang_file" required /></td></tr>
                <tr><td><input type="checkbox" name="include_new" value="1" checked><span class="new">include new</span></td><td><input type="text" name="new_prefix" value="_NEW:"> prefix of new value</td></tr>
                <tr><td><input type="checkbox" name="include_deprecated" value="1" checked><span class="deprecated">include deprecated</span></td><td><input type="text" name="deprecated_prefix" value="_DEPRECATED:"> prefix of deprecated value</td></tr>
                <tr><td><input type="checkbox" name="include_same" checked><span class="same">include same</span></td><td>&nbsp;</td></tr>
                <tr><td><input type="checkbox" name="include_conflicts" checked><span class="conflict">include structure conflicts</span></td><td>&nbsp;</td></tr>
                <tr><td colspan="2"><input type="checkbox" name="prefer_old_values" checked>prefer same values from the old file in result</td></tr>
                <tr><td>result format</td><td><input type="radio" name="format" value="xml">XML<br/><input type="radio" name="format" value="list" checked>color XML-like list</td></tr>
                <tr><td colspan="2" align="center"><input type="submit"></td></tr>
            </table>
        </fieldset>
    </form>
<? echo $footer; endif;
// http://php.net/manual/en/class.simplexmliterator.php
