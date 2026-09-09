<?php
$file = '/app/index.php';
$src = file_get_contents($file);

$replacements = [
    '.featuredVehicle{position:relative;' => '.featuredVehicle{display:block;position:relative;width:100%;',
    '.dashGrid{display:grid;' => '.dashGrid{display:grid;min-width:0;',
    '.panel{padding:22px}' => '.panel{padding:22px;min-width:0}',
    '.stat{padding:20px}' => '.stat{padding:20px;min-width:0;overflow:hidden}',
    '.stat strong{display:block;font-size:28px;' => '.stat strong{display:block;font-size:clamp(22px,2vw,28px);overflow-wrap:anywhere;',
    '@media(max-width:980px){' => '@media(max-width:1120px){.stats{grid-template-columns:repeat(2,1fr)}.dashGrid{grid-template-columns:1fr}}@media(max-width:980px){',
    'https://images.unsplash.com/photo-1592198084033-aade902d1aae?auto=format&fit=crop&w=1600&q=86' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/2017_Ferrari_488_GTB_Automatic_3.9_Front.jpg?width=1600',
    'https://images.unsplash.com/photo-1670727229851-79aee0a1d1a9?auto=format&fit=crop&w=1600&q=86' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/BMW_G82_M4_Competition_Tanzanite_Blue_Metallic_%2814%29.jpg?width=1600',
    'https://images.unsplash.com/photo-1606016159991-dfe4f2746ad5?auto=format&fit=crop&w=1600&q=86' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/Tesla_model_3_grey_%281%29.jpg?width=1600',
    'https://images.unsplash.com/photo-1666739339626-0b4cdec04a24?auto=format&fit=crop&w=1600&q=86' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/Toyota_Fortuner_GUN166_Legender_2.8_Q_4x2_Silver_Metallic_02.jpg?width=1600',
    'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1600&q=86' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/BMW_G82_M4_Competition_Tanzanite_Blue_Metallic_%2814%29.jpg?width=1600',
    'https://images.unsplash.com/photo-1592198084033-aade902d1aae?auto=format&fit=crop&w=1800&q=90' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/2017_Ferrari_488_GTB_Automatic_3.9_Front.jpg?width=1800',
    'https://images.unsplash.com/photo-1670727229851-79aee0a1d1a9?auto=format&fit=crop&w=1800&q=88' => 'https://commons.wikimedia.org/wiki/Special:Redirect/file/BMW_G82_M4_Competition_Tanzanite_Blue_Metallic_%2814%29.jpg?width=1800'
];

$src = strtr($src, $replacements);
file_put_contents($file, $src);
