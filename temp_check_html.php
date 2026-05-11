<?php
$html = file_get_contents('student/apply_event.php');
$divOpen = substr_count($html, '<div');
$divClose = substr_count($html, '</div>');
$mainOpen = substr_count($html, '<main');
$mainClose = substr_count($html, '</main>');
$asideOpen = substr_count($html, '<aside');
$asideClose = substr_count($html, '</aside>');
$sectionOpen = substr_count($html, '<section');
$sectionClose = substr_count($html, '</section>');
$scriptOpen = substr_count($html, '<script');
$scriptClose = substr_count($html, '</script>');
echo "div open=$divOpen close=$divClose\n";
echo "main open=$mainOpen close=$mainClose\n";
echo "aside open=$asideOpen close=$asideClose\n";
echo "section open=$sectionOpen close=$sectionClose\n";
echo "script open=$scriptOpen close=$scriptClose\n";
?>