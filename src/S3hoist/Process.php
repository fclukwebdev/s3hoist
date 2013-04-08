<?php

/* TO-DO:

* max height and width stuff
* check referrer - if its S3 then we don't want to check the S3 exact match

*/

namespace S3hoist;

use Aws\Common\Aws;
use Aws\S3\Enum\CannedAcl;
use Aws\S3\Exception\S3Exception;

class Process {

    // Define URL API parameters
    private $parametersAvaliable = array(
        "w" => array(
                "name" => "width",
                "pattern" => "[0-9]+"
            ),
        "h" => array(
                "name" => "height",
                "pattern" => "[0-9]+"
            ),
        "mw" => array(
                "name" => "maxWidth",
                "pattern" => "[0-9]+",
                "default" => 1500
            ),
        "mh" => array(
                "name" => "maxHeight",
                "pattern" => "[0-9]+",
                "default" => 1500
            ),
        "q" => array(
                "name" => "quality",
                "pattern" => "[0-9]+",
                "default" => 80
            )
    );

    private $awsAccessKey;
    private $awsSecretKey;

    private $parameters = array();

    private $originalObject;
    private $originalObjectContentType;
    private $originalImageWidth;
    private $originalImageHeight;
    private $originalImageType;

    private $createdObject;
    private $createdObjectData;

    private $createdS3Path;
    private $originalS3Path;

    public $originalURL;
    public $exactURL;
    public $localPath;

    public $scriptPath = '/s3hoist/process.php';
    public $s3SubFolder = '/s3hoist';

    public $displayObject = true;
    public $debug = false;

    public $checksToPerform = array(
        'checkS3Exact',
        'checkLocalOriginal',
        'checkS3Original'
    );

    public function __construct($awsAccessKey, $awsSecretKey) {

        $this->awsAccessKey = $awsAccessKey;
        $this->awsSecretKey = $awsSecretKey;

    }

    public function debug($message) {

        if($this->debug) {
            echo $message . "<br>";
        }

    }

    private function arrayMerge($array1, $array2) {

        if (!is_array($array1) or !is_array($array2)) { return $array2; }
        foreach ($array2 AS $key => $value) {
            $array1[$key] = $this->arrayMerge(@$array1[$key], $value);
        }
        return $array1;

    }

    private function getimagesizefromstr($str) {
        $uri = 'data://application/octet-stream;base64,' . base64_encode($str);
        return getimagesize($uri);
    }

    public function setParameters($parameters) {

        $this->parametersAvaliable = $this->arrayMerge($this->parametersAvaliable, $parameters);

    }

    public function go($displayObject = true){

        // Set whether object should be output to the browser
        $this->displayObject = $displayObject;

        // Process URL to check for parameters
        $this->processPath();

        $found = false;

        foreach($this->checksToPerform as $key => $value) {
            if($this->$value() === true) {
                $found = true ;
                break;
            }
        }

        if($found === false) {
            $this->debug('No object found');
        } else {
            $this->debug('Object found');
        }

    }

    private function processPath($path = '') {

        if($path === '') {$path = $_SERVER['REQUEST_URI'];}

        // Get S3 URL
        $exactURL = str_replace($this->scriptPath, S3_URL . $this->s3SubFolder, $path);

        // Get path, filename and extension
        $namePattern = "#([^\/]*)\.([^\.\/]*)\$#i";
        preg_match_all($namePattern, $exactURL, $fileName);
        $exactFilename = $fileName[0][0];
        $fileNoExtension = $fileNoParams = $fileName[1][0];
        $extension = $fileName[2][0];

        $parameterString = '';

        // Gather the parameters
        foreach ($this->parametersAvaliable as $key => $value) {

            // Gather default parameters
            $default = isset($value['default']) ? $value['default'] : NULL;
            $this->parameters[$this->parametersAvaliable[$key]['name']] = $default;

            // Gather any parameters found in the file name
            $paramPattern = '#_(' . $key . ')(' . $value['pattern'] . ')#i';
            preg_match_all($paramPattern, $fileNoExtension, $param);
            if(isset($param[1][0])) {
                $this->parameters[$this->parametersAvaliable[$param[1][0]]['name']] = $param[2][0];
            }
            $fileNoParams = preg_replace($paramPattern, '', $fileNoParams);

            // Gather any slash style parameters found in the URL
            $paramPattern = '#/(' . $key . ')_(' . $value['pattern'] . ')#i';
            preg_match_all($paramPattern, $exactURL, $param);
            if(isset($param[1][0])) {
                $this->parameters[$this->parametersAvaliable[$param[1][0]]['name']] = $param[2][0];
            }
            $exactURL = preg_replace($paramPattern, '', $exactURL);

            // Set the parameter string
            if($this->parameters[$this->parametersAvaliable[$key]['name']] !== NULL && $this->parameters[$this->parametersAvaliable[$key]['name']] !== $default) {
                $parameterString .= "_" . $key . $this->parameters[$this->parametersAvaliable[$key]['name']];
            }

        }

        $fileWithParameters = $fileNoParams . $parameterString . '.' . $extension;
        $fileNoParams = $fileNoParams . '.' . $extension;

        $this->originalURL = str_replace($exactFilename, $fileNoParams, $exactURL);
        $this->localPath = '..' . str_replace(array(S3_URL . $this->s3SubFolder, $exactFilename), array('', $fileNoParams), $exactURL);
        $this->originalS3Path = str_replace(array(S3_URL . $this->s3SubFolder, $exactFilename), array('', $fileNoParams), $exactURL);
        $this->createdS3Path = str_replace(array(S3_URL . $this->s3SubFolder, $exactFilename), array('', $fileWithParameters), $exactURL);
        $this->exactURL = str_replace($exactFilename, $fileWithParameters, $exactURL);

        $this->debug('originalURL: ' . $this->originalURL);
        $this->debug('localPath: ' . $this->localPath);
        $this->debug('originalS3Path: ' . $this->originalS3Path);
        $this->debug('createdS3Path: ' . $this->createdS3Path);
        $this->debug('exactURL: ' . $this->exactURL);

    }

    private function checkS3Exact() {

        // Check if the exact object is found on S3.
        $exact = $this->fetchObject($this->exactURL);

        if ($exact['code'] === 200) {

            $this->debug('<b>Object found at exactURL: ' . $this->exactURL . '</b>');

            if (!$this->debug) {
                // exact object is found, redirect.
                header("Location: " . $this->exactURL);
            }

            return true;

        }

        return false;

    }

    private function checkLocalOriginal() {

        if(file_exists($this->localPath) && is_file($this->localPath) && is_readable($this->localPath)) {

            $this->debug('<b>Object found at localPath: ' . $this->localPath . '</b>');

            $this->processObject(file_get_contents($this->localPath), true);

            return true;

        }

        return false;

    }

    private function checkS3Original() {

        // Check if the original object is found on S3.
        $original  = $this->fetchObject($this->originalURL, true);

        if ($original['code'] === 200) {

            $this->debug('<b>Object found at originalURL: ' . $this->originalURL . '</b>');

            // original is found, process it into the requested version.
            $this->processObject($original['data']);

            return true;

        }

        return false;

    }

    // Check if exact file exists at the URL provided
    public function fetchObject($url, $download = false) {

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, !$download);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $download);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'code' => intval($code),
            'data' => $data
        );

    }

    // Process object
    public function processObject($originalObjectData, $sendOriginaltoS3 = false) {

        $finfo = new \finfo(FILEINFO_MIME);
        $temp = explode(';', $finfo->buffer($originalObjectData) . ";");
        $mime = strtolower($temp[0]);
        $temp = explode('/', $finfo->buffer($originalObjectData) . "/");
        $type = strtolower($temp[0]);

        $this->debug('Type: ' . $type);
        $this->debug('Mime: ' . $mime);

        switch($type)
        {
            case 'image':
                $this->processImage($originalObjectData, $sendOriginaltoS3);
                break;
            default:
                $this->processFile($originalObjectData, $mime);
        }

        if($this->displayObject) {
            $this->outputObject();
        }

        $this->sendToS3($this->createdObjectData, 'created');

    }

    public function processImage($originalImageData, $sendOriginaltoS3 = false) {

        $this->debug('Proccessing image');

        $this->originalObject = imagecreatefromstring($originalImageData);
        $originalImageProperties = $this->getimagesizefromstr($originalImageData);
        $this->originalImageWidth = intval($originalImageProperties[0]);
        $this->originalImageHeight = intval($originalImageProperties[1]);
        $this->originalImageType = $originalImageProperties[2];
        $this->originalObjectContentType = $originalImageProperties['mime'];

        $this->debug('ImageProperties: <pre>' . print_r($originalImageProperties, true) . '</pre>');

        $this->resizeImage();

        if($sendOriginaltoS3 && !$this->debug) {
            $this->sendToS3($originalImageData, 'original');
        }

    }

    private function resizeImage() {

        $originalAspect = $this->originalImageWidth / $this->originalImageHeight;

        $maxWidth = $this->parameters['maxWidth'];
        $maxHeight = $this->parameters['maxHeight'];
        $desiredMaxWidth = $maxWidth === NULL ? $this->originalImageWidth : $maxWidth;
        $desiredMaxHeight = $maxHeight === NULL ? $this->originalImageHeight : $maxHeight;

        $hardWidth = false;
        $hardHeight = false;

        $width = $this->parameters['width'];
        $height = $this->parameters['height'];

        if($width === NULL) {
            if($height === NULL) {
                $desiredWidth = $this->originalImageWidth;
            } else {
                $desiredWidth = $this->originalImageWidth * $height / $this->originalImageHeight;
            }
        } else {
            $desiredWidth = $width;
            $hardWidth = true;
        }

        if($height === NULL) {
            if($width === NULL) {
                $desiredHeight = $this->originalImageHeight;
            } else {
                $desiredHeight = $this->originalImageHeight * $width / $this->originalImageWidth;
            }
        } else {
            $desiredHeight = $height;
            $hardHeight = true;
        }

        $desiredAspect = $desiredWidth / $desiredHeight;

        // Adjust width if larger than max width - maintain aspect ratio
        if($desiredWidth > $desiredMaxWidth) {
            $desiredWidth = $desiredMaxWidth;
            if($hardHeight === false) {
                $desiredHeight = $desiredWidth / $desiredAspect;
            }
        }

        // Adjust width if larger than max height - maintain aspect ratio
        if($desiredHeight > $desiredMaxHeight) {
            $desiredHeight = $desiredMaxHeight;
            if($hardWidth === false) {
                $desiredWidth = $desiredHeight * $desiredAspect;
            }
        }

        $desiredAspect = $desiredWidth / $desiredHeight;

        $this->debug('Requested width: ' . $desiredWidth);
        $this->debug('Requested height: ' . $desiredHeight);

        if ($originalAspect >= $desiredAspect) {
            // If image is wider than thumbnail (in aspect ratio sense)
            $newHeight = $desiredHeight;
            $newWidth = $this->originalImageWidth / ($this->originalImageHeight / $desiredHeight);
        } else {
            // If the thumbnail is wider than the image
            $newWidth = $desiredWidth;
            $newHeight = $this->originalImageHeight / ($this->originalImageWidth / $desiredWidth);
        }

        $this->createdObject = imagecreatetruecolor($desiredWidth, $desiredHeight);

        imagealphablending($this->createdObject, false);
        imagesavealpha($this->createdObject, true);

        imagecopyresampled(
            $this->createdObject,
            $this->originalObject,
            0 - ($newWidth - $desiredWidth) / 2,
            0 - ($newHeight - $desiredHeight) / 2,
            0,
            0,
            $newWidth,
            $newHeight,
            $this->originalImageWidth,
            $this->originalImageHeight
        );

        ob_start();
            switch($this->originalImageType)
            {
                case IMAGETYPE_GIF:
                    imagegif($this->createdObject);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($this->createdObject, NULL, round((100.01 - $this->parameters['quality']) / 10));
                    break;
                default: //IMAGETYPE_JPEG
                    imagejpeg($this->createdObject, NULL, $this->parameters['quality']);
            }
        $this->createdObjectData = ob_get_clean();

        imagedestroy($this->originalObject);
        imagedestroy($this->createdObject);

    }

    public function processFile($originalObjectData, $mime) {

        $this->originalObjectContentType = $mime;
        $this->createdObjectData = $originalObjectData;

    }

    public function outputObject() {

        if(!$this->debug) {

            header('Content-type: ' . $this->originalObjectContentType);

            echo $this->createdObjectData;

        }

    }

    private function sendToS3($data, $type) {

        switch($type)
        {
            case 'original':
                $path = $this->originalS3Path;
                break;
            default:
                $path = $this->createdS3Path;
        }

        $path = ltrim($this->s3SubFolder . $path, '/');

        $this->debug('Attempting to write the following to S3 at path: ' . $path);
        $this->debug($data);

        if(!$this->debug) {
            // Instantiate an S3 client
            $s3 = Aws::factory(array(
                'key'    => $this->awsAccessKey,
                'secret' => $this->awsSecretKey
            ))->get('s3');

            try {
                $s3->putObject(array(
                    'Bucket'      => BUCKET,
                    'Key'         => $path,
                    'ContentType' => $this->originalObjectContentType,
                    'Body'        => $data,
                    'ACL'         => CannedAcl::PUBLIC_READ
                ));
            } catch (S3Exception $e) {
                $this->debug('The file was not uploaded');
            }
        }

    }

}
