<?php
libxml_use_internal_errors(true);
$html = file_get_contents('student/apply_event.php');
$doc = new DOMDocument();
$doc->loadHTML($html);
function walk($node, $depth = 0) {
    if ($node->nodeType === XML_ELEMENT_NODE) {
        echo str_repeat('  ', $depth) . $node->nodeName;
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attr) {
                if ($attr->name === 'class' || $attr->name === 'id') {
                    echo ' ' . $attr->name . '="' . $attr->value . '"';
                }
            }
        }
        echo "\n";
    }
    foreach ($node->childNodes as $child) {
        walk($child, $depth + 1);
    }
}
walk($doc->documentElement);
?>