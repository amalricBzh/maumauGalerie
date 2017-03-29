<?php

/**
 * Les expressions "if ... else ..." nécessitent des "else", sinon je ne les mettrais pas.
 * C'est donc une bonne pratique de codage, je supprime ces warnings.
 * @SuppressWarnings(PHPMD.ElseExpression)
 */

define("VIGNETTE_FOLDER", "vignettes");
define("VIGNETTE_WIDTH", 150);
define("CONFIG_FILENAME", VIGNETTE_FOLDER . "/config.json");
define("ALLEGEE_WIDTH", 900);
define("JPEG_RATE", 70);

ob_start();

// Default action
$action = 'form';
if (file_exists(CONFIG_FILENAME)){
    // Read config file, if to, do it, else, display
    $config = json_decode(file_get_contents(CONFIG_FILENAME), true);
    if (count($config['todo']) > 0) {
        $action = 'generate' ;
    } elseif (count($config['done']) >0) {
        $action = 'display';
    }
}
// If action from url, use it
if (isset($_GET['action'])){
    $action = $_GET['action'];
}

// Si on vient du formulaire, on le vérifie
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action="generateConfigFile" ;
}

switch($action){
    case 'generateConfigFile':
        generateConfigFile();
    break;
    case 'generate':
        generateGallery($config);
    break;
    case 'display':
        displayGallery($config);
    break;
    
    case 'reset':
        // Efface le répertoire des vignettes (avec la config)
        @rrmdir(VIGNETTE_FOLDER);
	    header('Location:'.getScriptUrl());
    break;
    
    default:
        // Sinon, on affiche le formulaire
        displayFormGeneration();
    break;
}

ob_end_flush();


/***** Action functions *****/
function generateGallery($config)
{
    $reloadDuration = 1 ; // En cas d'erreur, on prolongera ce délai
    $errorMessage = '';
    $startTime = microtime(true);
    // On traite au moins 2 secondes d'images
    while(microtime(true) - $startTime < 2 && count($config['todo']) > 0) {
        // Création de la vignette
        $index = count($config['done']);
        $filename = $config['todo'][0];
        $result = createImage($filename, $config['thumbSize'], VIGNETTE_FOLDER. '/vignette_'.$index.'.jpg');
        if (isset($result['error'])){
            $errorMessage .= $result['error'];
            $reloadDuration = 10 ;
            $config['failed'][] = $filename ;
        } else {
            list($width, $height) = getimagesize($filename);
            $config['done'][$index] = [
                'filename' => $filename,
                'width' => $width,
                'height' => $height,
                'filesize' => formatSizeUnits(filesize($filename)),
                'vignette' => $result
            ] ;
            // Création de l'image allégée
            $result = createImage($config['todo'][0], ALLEGEE_WIDTH, VIGNETTE_FOLDER. '/allegee_'.$index.'.jpg');
            $config['done'][$index]['allegee'] = $result ;
        }
        
        
        // Retire le fichier des todo et le met dans les erreurs
        unset($config['todo'][0]);
        // Reindex keys
        $config['todo'] = array_values($config['todo']);

        writeConfigFile($config);
    }
    // Display page
	$todoNb = count($config['todo']);
	$doneNb = count($config['done']);
    displayHeader($config['title'], $config['subTitle']);
    ?>
        <h1>Génération des vignettes</h1>
        <p>Vignettes <?= $doneNb ?>/<?= $doneNb+ $todoNb ?> faites.</p>
        <p>Veuillez patienter...</p>
    <?php 
    echo $errorMessage ;
    displayFooter();
    // Reload
	header('Refresh: '.$reloadDuration.';URL='.getScriptUrl());
}

function createImage($source, $size, $destination)
{
    // Création de la vignette
    $sourceImage = @imagecreatefromjpeg($source);
    if (!$sourceImage) {
        $errorMessage = "<p>Erreur : " . $source . " n'est pas un fichier jpg lisible.</p>";
        return ['error' => $errorMessage];
        
    }
    // Exif infos
    $exifDatas = @exif_read_data($source, 'FILE', true, false);
    $exif = [
        'orientation' => 1
    ];
    if ($exifDatas !== false) {
        if (!empty($exifDatas['IFD0']['Orientation'])){
            $exif['orientation'] = $exifDatas['IFD0']['Orientation'];
        }
    }
    // Init création image
    $width = imagesx($sourceImage);
    $newWidth = $width ;
    $height = imagesy($sourceImage);
    $newHeight = $height ;
    if ($newWidth > $size){
        $newWidth = $size ;
        $newHeight = floor($height * $size / $width);
    }
    if ($newHeight > $size){
        $newHeight = $size ;
        $newWidth = floor($width * $size / $height);
    }
    $vignette = imagecreatetruecolor($newWidth, $newHeight);
    // Copie source dans la vignette avec changement de la taille
    imagecopyresampled($vignette, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    // Tourne la vignette
    switch($exif['orientation']) {
        case 3:
            $vignette = imagerotate($vignette, 180, 0);
            break;
        case 6:
            $vignette = imagerotate($vignette, 270, 0);
            break;
        case 8:
            $vignette = imagerotate($vignette, 90, 0);
            break;
    }
    
    imagejpeg($vignette, $destination);
    
    return [
        "filename" => $destination,
        "width" => $newWidth,
        "height" => $newHeight,
        "exif" => $exif
    ];
}

function generateConfigFile()
{
    $errorMessage = '';
    // Création du répertoire
    if (!is_dir(VIGNETTE_FOLDER) && !mkdir(VIGNETTE_FOLDER, 0777)) {
        $errorMessage .='<p>Echec lors de la création du répertoire des vignettes...</p>';
    }
    
    $config = [
        "title"      => $_POST['title'],
        "subTitle"   => $_POST['subTitle'],
        "thumbSize"   => intval($_POST['thumbSize']) >= 50 ? intval($_POST['thumbSize']): VIGNETTE_WIDTH,
        "todo"       => [],
        "done"       => [],
        "failed"     => [],
    ];
    // Read the images
    foreach (glob("*.[jJ][pP][gG]") as $filename) {
        $config['todo'][] = $filename ;
    }
    // Write the config file
    writeConfigFile($config);
    
    displayHeader($config['title'], $config['subTitle']);
    ?>
        <h1>Génération de la configuration</h1>
        <p>Veuillez patienter...</p>
    <?php 
    echo $errorMessage ;
    displayFooter();
    // Si pas d'erreur, recharge la page dans 2s
    if (strlen($errorMessage) === 0){
        header('Refresh: 2;URL='.getScriptUrl());
    }
}

function writeConfigFile($config)
{
    file_put_contents(CONFIG_FILENAME, json_encode($config, JSON_PRETTY_PRINT));
}
/****** Utils ****/
// var_dump die, pour debug
function vdd($var)
{
    echo '<pre>';
    var_dump($var);
    die;
}

function getScriptUrl()
{
    return strtok($_SERVER["REQUEST_URI"],'?');
}

function formatSizeUnits($size)
{
    $bytes = '0 bytes';
    if ($size >= 1073741824) {
        $bytes = number_format($size / 1073741824, 2, ',', ' ') . ' Go';
    } elseif ($size >= 1048576) {
        $bytes = number_format($size / 1048576, 2, ',', ' ') . ' Mo';
    } elseif ($size >= 1024) {
        $bytes = number_format($size / 1024, 2, ',', ' ') . ' Ko';
    } elseif ($size > 1) {
        $bytes = $size . ' octets';
    } elseif ($size === 1) {
        $bytes = '1 octet';
    }
    return $bytes;
}

function rrmdir($dir) { 
   if (is_dir($dir)) { 
     $objects = scandir($dir); 
     foreach ($objects as $object) { 
       if ($object != "." && $object != "..") { 
         if (is_dir($dir."/".$object))
           rrmdir($dir."/".$object);
         else
           unlink($dir."/".$object); 
       } 
     }
     rmdir($dir); 
   } 
 }


/***** "Templates" *****/

function displayFormGeneration()
{
    $titre = "Génération d'un nouvel album" ;
    $sousTitre = "Veuillez remplir le formulaire";
    displayHeader($titre, $sousTitre);
    ?>
<form method="post">
  <div class="form-group">
    <label for="title">Nom</label>
    <input type="text" name="title" class="form-control" id="title" placeholder="Le nom de votre album" pattern=".{3,}" required title="3 caractères minimum">
  </div>
  <div class="form-group">
    <label for="subTitle">Description</label>
    <input type="text" name="subTitle" class="form-control" id="subTitle" placeholder="Une courte description de votre album" pattern=".{5,}" required title="5 caractères minimum">
  </div>
  <div class="form-group">
    <label for="thumbSize">Taille des vignettes (en pixels)</label>
    <input type="number" name="thumbSize" class="form-control" id="thumbSize" placeholder="<?= VIGNETTE_WIDTH ?>" min="50" max="500" required title="Un nombre entre 50 et 500" value="<?= VIGNETTE_WIDTH ?>">
  </div>
  <button type="submit" class="btn btn-default">Générer</button>
</form>
    <?php
    displayFooter();
}

function displayGallery($config)
{
    displayHeader($config['title'], $config['subTitle']);
    echo '<div class="galerie">';
    foreach($config['done'] as $key => $image){
        ?>
        <div class="imageContainer">
            <img src="<?= $image['vignette']['filename'] ?>" width="<?= $image['vignette']['width'] ?>" height="<?= $image['vignette']['height'] ?>">
            <div class="icon">
                <a href="<?= $image['allegee']['filename'] ?>" target="_blank" title="Maumau galerie - <?= $config['title']. ' - ' .$config['subTitle'] ?>" data-fancybox="gallery" data-width="<?= $image['allegee']['width'] ?>" data-height="<?= $image['allegee']['height'] ?>"><i class="fa fa-picture-o fa-2x" aria-hidden="true"></i></a>
                <a download="<?= $image['filename'] ?>" href="<?= $image['filename'] ?>" target="_blank" title="Télécharger l'image originale"><i class="fa fa-download fa-2x" aria-hidden="true"></i></a>
            </div>
            <div class="imageInfos"><?= $image['filename'] ?><br />
            <?= $image['width'] ?>x<?= $image['height'] ?>, <?= $image['filesize'] ?></div>
        </div>
        <?php
    }
    echo '</div>' ;
    
    displayFooter();
}

function displayHeader($titre = "Album", $sousTitre = "généré automatiquement")
{
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Autogalerie</title>
        <link rel="icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAM8klEQVR4XuWbe1BTdxbHv/fmDeEhb4RCRaSIzECVVmjFx3RbLZGptWW1w2ItW0nF4TEdp7Oztrt2W9ddyraCuIVtR9Gto4KMbUG0gkULtlSEiiIiRaw85CVBISEhl+TuH4GYF7khCcWunxlH7u93zoFz7u93fs9LYAbh8JxI94AIL5/gp6PdAqKWuHk/ESpw9g3i8Jx82FyhGwD+hKhiXCmVUGMjvfLhnnZJ340WScel+t62i7WDHY391NiI2tzvsQWCSWC6cAXOZEBEXGTgkoQEvwWxa/kCz3AmHXMo5ANN3T9Xl92uLy7uaCy/rJQP2zUYdguAi2+Ic/iqbZvmP7VRzHfwssnpqVCM9jfdrDta0FS179D9ntZhJnlLsDkALr4hzpEvvpc5/8lXMlgcgRths0Xz0DSgouSSmz+V5Fw+9cEeWwNh9Z/LdXQml7y0IznsmW27WBxHLyb5mUBFyfqbv9+3o/6rXfuVMuu6hlUB8F+4KnjZH/IPOLuHLLPOgh2hgeHB1pqaL956o+t6VRuTuCEsJgFdSBaHXPrqzsRnNuSX8oVewbPuPAAQAM/BPWD+U68lcx24nXdu1DTRtJpmUpvE4gCwHV3ZL2w59nFIzJZ/EiSLyyT/a0OQLK530Ir1ngFPzvnl2qlKNaWwqEtY9A55Tj58UXrpEffHotbNdJKzFZoGBjsvfXkyN/61sZFeBZM8ozs8p7n8te9UnnD3XLiGWfohgQYGB66fLsv63ctjI3fMBoE0V8kTuLJFb5cemcp5ARd4aj7A4xjXzSoE4O65cI3o7dIjPIEr25zolDmAYPHI51OPZ/vOi91s6DyPDbwYCTzuCfzQCijHTduYVQjAQegb6v54pPPNuuMVoFUmE+OUAYh+dVdiyNLNWbrO0zSwJAhIeR74vhW4cANQWZRqZgkCcPEMjubwOG1dzZVXphAxJiByTfALKV/9RLK4wskyHgdIXAZEPA7klgM3+0xpTg1NWzwyWQxhYUZWq5TSM/956cmOy6eN5glGFrjCOezfv3u5ymFOwLLJMg8nYNsawNsFyDsNNHcZak0NTdPoaj6NxjMfQaWUM4lbDEfgjKj49+E1L5pJFAAwOtRRU/Rh5CqldEivwxoliKXxOzfrOu/jCry9FnAWAMW1ppw3/WZpWvOGxpUyVBQkYHxMZlLOFobuNCPxHx2wYDCDw5yAZUvjd26uPpLxuW65XgDc/MOcQ5Zt2TX57OoAZIoAFwfgagdw9iqMaP2hEBeOZoBSjGjLWBw+ouL/gojVf8LY6NCMOA8AsqFpNEUAIcu27LpWXVAk6WrWLqD0AhC19oNMFlvgBWhi+uZzwBxHQKEEvqielKIhldyG7F4PCMDIeQBQUQpcPPEuPAKjQI2NYibpb6/VtkGCIODsNR88Bw+T+YHFFnhFrf0g80z+K3+bLNNKufqFO7/6bsMtkuS4AUBMCPD6Ck3d15eA8p8Ahewuzh14HR1XT2Gqpj/bkCwOFq1KxdJXPgJJGk9Q1GpKcvzDxfPudTcNAzotICx26yaC0DhP08Bz4QBBAHIl8G2TJplVFiTgzo1zRkZng40bN8LX19dkXVlZGeq/FiDqpb8btQSC4LiFxW7d9P3RbXnARAC4ji5k8NMJ4klZDyfA313z88U2QEEBg52XHhrnASA9PR0xMTEm67q6unDi671YvPavYLH50IUggOCnE8SXSv/8b6XsvpoEAL8wUSTf8cHeXZC3RpCmgbqbmrLhu7/gt8T4mAyKkbsm6/iOnuF+YaJIYGItEPzUywm6AnPdNP/LxoC23onCh7DL0zQ95T8AoM380ZM+szlcJ9IneMVa3Ur3ifnftU5NK7AXXC4XcXFxWL16NSIiIuDj4wMA6O3tRWNjI7755huUl5dDqVQyWALWrVsHLtf0tsTg4KDJcl18gles5XCddrDdAyO8BI76W9fCiW7TcseU6vTh8XhIS0vD9u3b4eXlZZSY5s2bh5iYGIjFYvT39yM7Oxt79+7F2NjYFBaB/v7+KessQeDoGe4eGOFF+gRHG80leRzNm7/ZC5uJiIhAQ0MDsrKy4O3tbXb+ThAEvL29kZWVhYaGBkREREwpaw98gqOj2e7+i5cYVkwOf333TalZTlxcHI4dOwahUAhdxsfHce3aNdy6dQuApgUsWrQIbLZmVCYIAmFhYaipqcGGDRtQXl5uZNseuPsvXsJ281sYalhB08CdIVMqlrNq1SqUlJSAz38wDEkkEmRnZ2P//v3o69NfTnp7eyM5ORnbt2+Hm5smCwuFQpSUlCAuLg5VVVWwN25+C0NJvpNfkGEFpQJ675lSsQx/f38UFRVpnadpGhUVFQgLC8Pu3buNnAeAvr4+7N69G2FhYaioqNBmcj6fj6KiIvj7+xvp2ArfyS+I5HAFPoYV8jHgrg3nLfv27YOHhwcAjfMlJSUQiUQmHTekr68PIpEIJSUl2iB4eHhg3759DJrTh8MV+JATp7R6SBXAPSvXMLGxsYiPj9c+NzY2IikpCRRFmdECEhMTUVVVhfT0dFAUhaSkJDQ2Nmrr4+PjERsba8bC9GFzhW4kHhxRa7k/CoxYuXeRnp6uzfQ0TSMlJQUKhfnd6cDAQBQWFmLlypXYs2cPoqOjoVAokJKSom0FBEEgPT3drB0r4JvcFR6UakaB6SIQCCASibTPFRUVqKurM6Nhnrq6OlRUVGifRSIRBAKBGY3pQwIwej13hzWJcLpERUXpZf2ioiIz0g+4ffs2Nm/ejHPnziEzMxO1tbXaOl0bfD4fUVFRpkxYi4I9rpRK2FzhXN3S3nuAkxWBXrBggV7zr6+vZ9B4wOHDh3H48GGj8vr6etA0DYIgQBAEFixYgOrqatiDcaVUQlJKudF8b0QB9FgxD3B2dtZ7tmROzoShDcPfYQuUUt5LKka6201VWrMGksn09/5cXFymkLQcQxuGv8MWFCPd7aSk+3oLk6CltLe362XtyMhIBg1mIiMj9bpVe7vJ92UVku7rLeRgV4PlHZWBuro6vfF+/fr1ZqQtQ9cGRVE2jSqGDHY11JO9bTop10aGh4dx9uxZ7XN8fDxCQ42WGhYTGhqqN6k6e/YshodtmKIa0NtWW0sO3m7sl8sGmpiELSUvL0/bDdhsNvLz88FiWXwPQwuLxUJ+fr52hUjTNPLy8hi0LEcuG2gavN3YT1LKEXVv2/kyJgVLKS8v1xumli9fjpycHIvP8QBN/sjJycHy5cu1ZdXV1XZdFve2nS+jlCOaTdG2uhPFTArTQSwWQyqVAtA4k5qaisLCQjg6OjJoAo6OjigsLERqaqo2aFKpFGKxmEFzekz6TAJAd/PJywo7doOWlhYkJydDpdJMJwmCQFJSEq5cuYKkpCTweDwjHR6Ppycz6bxKpUJycjJaWuw2WEEhG2jqbj55GZg4F1DK7qvbLhYXLFqZuncaLdUsxcXFEAqFKCgoAIfDAUEQCAoKwsGDB5Gbm4sLFy7o7Qg9++yzcHFx0esqFEVBLBajuNh+DZSmgbaLxQVK2X01oHMy1Fz96aGwFVvenzwdsgcHDhxAZ2cnDh06pD3FIQgCrq6ueosmU/T09GDTpk2orKw0KzddaJqSNFd/emjyWbsavNfdNNzRWJpjWs16KisrER4ejtzcXMjlzGtsuVyO3NxchIeH2915AOhoLM2ZPBcEDC5JXSp7b49qXG7bfrMJJBIJMjIyEBgYiLS0NJSVlaGzsxMURYGiKHR2dqKsrAxpaWkIDAxERkYGJBIJk9lpoxqX918qe2+Pbpne8bikq3m4teazHQtXpn+GGWBgYAB5eXl2Hc+nQ2vNZzt07wYAJq7J/Vi6s3B0qKPGsPy3iG4+Hx3qqPmxdGehoYxRAJTSofHvjorfUKuUUt1yR1fTR9EPKyw2D7yJ7U61Sin97qj4DcP7QcAUFyU7Lp9ua6r6ZKtumde8GLg/Zvvq7tci5JnXwWY7AACaqj7ZauqGGGDmnmD3jeomr6AoFxeP4GgQAEGQeGzRGvT+fB6j9+1wZjaDBC1Zj9jEApAsLjpbTu85f/Ctf011UdLstIcncGWL3jlb7OG7eN2kJK1Wof/WD5Dd6zGnOisQBAFXnycwZ244QBO429Pw5cms5xLG5PemvMvKOO/7f78szbhOVSlHxtsvlZT4L1wZLnCeG2qvqfJMob0u//GahLGRHsbr8owBAACVUjr+c0NxiadfuKuLd8hSJvnZpKupLPdU/stvUtK7Fp1sWBQAAFBTCnV7/fEzbB7aPANjXnjYvhpRq5TSq9/+/Y/f/Xdbtmps1OJTDasa9CP70dQkw3d/kbT+eOggS8Dq8vCLjCZZXOadjhlARcn6r9Xsyfz286SMoTs3rDqEsPn9PbIfThryyH46a8gj+/G0KTg8J9ItIMLL18rP53vaLtZKZvjz+f8BAgtY6De/t8cAAAAASUVORK5CYII=" type="image/x-icon">
        <!-- Bootstrap : Latest compiled and minified CSS -->
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
        <!-- Bootstrap : Optional theme -->
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css" integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.0.47/jquery.fancybox.min.css" crossorigin="anonymous">
        <?php
            echo customCss();
        ?>
    </head>
    <body>
        <header>
        <div class="container">
        <span class="title"><?= $titre ?></span> - <span class="subTitle"><?= $sousTitre ?></span>
        </div>
        </header>
        <div class="container">
    <?php
}

function displayFooter()
{
    ?>
        </div>
        <footer class="footer">
            <div class="container">
                <p>Maumau Galerie 0.2, mars 2017</p>
            </div>
        </footer>
        <!-- jQuery -->
        <script src="https://code.jquery.com/jquery-3.1.1.min.js" integrity="sha256-hVVnYaiADRTO2PzUGmuLJr8BLUSjGIZsDYGmIJLv2b8=" crossorigin="anonymous"></script>
        <!-- Bootstrap : Latest compiled and minified JavaScript -->
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
        <!-- Fancybox -->
        <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.0.47/jquery.fancybox.min.js"></script>
    </body>
    </html>
    <?php
}

function customCss()
{
    return "<style>
html {
  position: relative;
  min-height: 100%;
}
body {
  /* Margin bottom by footer height */
  margin-top: 50px;
  margin-bottom: 40px;
}
header {
  position: absolute;
  top: 0;
  width: 100%;
  height: 50px;
  background-color: #00008B;
  color: white;
}

header .title {
    font-weight: bold;
}

footer {
  position: absolute;
  bottom: 0;
  width: 100%;
  height: 40px;
  background-color: #e5e5e5;
  overflow: hidden;
}


/* Custom page CSS
-------------------------------------------------- */
/* Not required for template or sticky footer method. */

.container {
  width: auto;
  min-width:400px;
  padding: 15px 100px;
}
.galerie {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: center;
    align-items: baseline;
}
.galerie div {
    margin: 5px;
    padding: 5px;
    border: 1px solid #ddd ;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: center;
    position: relative;
}
div.imageInfos {
    color: #888;
    padding: 0;
    border:0;
    font-size: 0.8em;
    text-align: center;
}

footer .container p {
    text-align: center;
    text-overflow: fade(10px);
}

input[type=\"number\"] {
    width: 100px;
}

div.icon {
    display: none;
    background-color: rgba(225,230,250,0.55);
    border-radius : 10px;
    
}

div.imageContainer {
    margin: 5px;
}

div.imageContainer:hover div.icon {
    display: flex;
    flex-direction: row;
    color: #555;
    border: none;
    padding: 5px;
    top: 5px;
    left: 5px;
    position: absolute;
    z-index: 5;
}

div.icon a {
    color: #555;
    text-decoration: none;
    padding: 0 5px ;
}

</style>";
}
