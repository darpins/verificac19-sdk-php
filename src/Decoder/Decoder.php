<?php
namespace Herald\GreenPass\Decoder;

use CBOR\ByteStringObject;
use CBOR\ListObject;
use CBOR\StringStream;
use CBOR\TextStringObject;
use CBOR\OtherObject\OtherObjectManager;
use CBOR\Tag\TagObjectManager;
use Herald\GreenPass\GreenPass;
use Mhauri\Base45;

class Decoder
{

    const LIST = 'list';

    const JSON = 'json';

    const GET_CERTIFICATE_FROM = 'list';
    
    const HOUR_BEFORE_DOWNLOAD_LIST = 24;

    private static function base45($base45)
    {
        try {
            $decoder = new Base45();

            return $decoder->decode($base45);
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function zlib($zlib)
    {
        try {
            return zlib_decode($zlib);
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function cose($cose)
    {
        $stream = new StringStream($cose);

        $tagObjectManager = new TagObjectManager();
        $tagObjectManager->add(CoseSign1Tag::class);
        $cborDecoder = new \CBOR\Decoder($tagObjectManager, new OtherObjectManager());

        $cbor = $cborDecoder->decode($stream); // We decode the data
        if (! $cbor instanceof CoseSign1Tag) {
            throw new \InvalidArgumentException('Not a valid certificate. Not a CoseSign1 type.');
        }

        $list = $cbor->getValue();
        if (! $list instanceof ListObject) {
            throw new \InvalidArgumentException('Not a valid certificate. No list.');
        }

        if (4 !== $list->count()) {
            throw new \InvalidArgumentException('Not a valid certificate. The list size is not correct.');
        }

        return $list;
    }

    private static function cbor($list)
    {
        $decoded = array();
        $tagObjectManager = new TagObjectManager();
        $tagObjectManager->add(CoseSign1Tag::class);
        $cborDecoder = new \CBOR\Decoder(new TagObjectManager(), new OtherObjectManager());

        $h1 = $list->get(0); // The first item corresponds to the protected header
        $headerStream = new StringStream($h1->getValue()); // The first item is also a CBOR encoded byte string
        $decoded['protected'] = $cborDecoder->decode($headerStream)->getNormalizedData(); // The array [1 => "-7"] = ["alg" => "ES256"]

        $h2 = $list->get(1); // The second item corresponds to unprotected header
        $decoded['unprotected'] = $h2->getNormalizedData(); // The index 4 refers to the 'kid' (key ID) parameter (see https://www.iana.org/assignments/cose/cose.xhtml)

        $data = $list->get(2); // The third item corresponds to the data we want to load
        if (! $data instanceof ByteStringObject) {
            throw new \InvalidArgumentException('Not a valid certificate. The payload is not a byte string.');
        }
        $infoStream = new StringStream($data->getValue()); // The third item is a CBOR encoded byte string
        $decoded['data'] = $cborDecoder->decode($infoStream)->getNormalizedData(); // The data we are looking for

        $signature = $list->get(3); // The fourth item is the signature.
                                    // It can be verified using the protected header (first item) and the data (third item)
                                    // And the public key
        if (! $signature instanceof ByteStringObject) {
            throw new \InvalidArgumentException('Not a valid certificate. The signature is not a byte string.');
        }
        $decoded['signature'] = $signature->getNormalizedData(); // The digital signature

        return $decoded;
    }

    // Retrieve from JSON Country/KID
    private static function retrieveCertificates()
    {

        // We retrieve the public keys
        $current_dir = dirname(__FILE__);
        $uri = "$current_dir/../../assets/dsc.json";

        // We decode the JSON object we received
        $certificates = json_decode(file_get_contents($uri), true, 512, JSON_THROW_ON_ERROR);
        return $certificates;
    }

    // Retrieve from RESUME-TOKEN KID
    private static function retrieveCertificateFromList($list, $resume_token = "")
    {
        // We retrieve the public keys
        $ch = curl_init('https://get.dgc.gov.it/v1/dgc/signercertificate/update');

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (! empty($resume_token)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "X-RESUME-TOKEN: $resume_token"
            ));
        }

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        // Then, after your curl_exec call:
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);

        // Convert the $headers string to an indexed array
        $headers_indexed_arr = explode("\r\n", $headers);

        // Define as array before using in loop
        $headers_arr = array();

        // Create an associative array containing the response headers
        foreach ($headers_indexed_arr as $value) {
            if (false !== ($matches = explode(':', $value, 2))) {
                $headers_arr["{$matches[0]}"] = trim($matches[1]);
            }
        }

        if (empty($info['http_code'])) {
            throw new \InvalidArgumentException("No HTTP code was returned");
        }

        if ($info['http_code'] == 200) {
            $list[$headers_arr['X-KID']] = $body;
            return static::retrieveCertificateFromList($list, $headers_arr['X-RESUME-TOKEN']);
        } else
            return $list;
    }

    private static function validateKidCountry(array $cbor, $certificates)
    {
        // We filter the keyset using the country and the key ID from the data
        $country = $cbor['data'][1];
        $keyId = "";

        if (is_array($cbor['unprotected']) && isset($cbor['unprotected'][4])) {
            $keyId = base64_encode($cbor['unprotected'][4]);
        }

        if (is_array($cbor['protected']) && isset($cbor['protected'][4])) {
            $keyId = base64_encode($cbor['protected'][4]);
        }

        if (empty($keyId)) {
            throw new \InvalidArgumentException('Invalid KID');
        }

        $countryCertificates = array_filter($certificates['certificates'], static function (array $data) use ($country, $keyId): bool {
            return $data['country'] === $country && $data['kid'] === $keyId;
        });

        // If no public key is found, we cannot continue
        if (1 !== count($countryCertificates)) {
            throw new \InvalidArgumentException('Public key not found in json');
        }

        return current($countryCertificates);
    }

    private static function validateKidList(array $cbor, $certificates)
    {
        // We filter the keyset using the country and the key ID from the data
        $keyId = "";

        if (is_array($cbor['unprotected']) && isset($cbor['unprotected'][4])) {
            $keyId = base64_encode($cbor['unprotected'][4]);
        }

        if (is_array($cbor['protected']) && isset($cbor['protected'][4])) {
            $keyId = base64_encode($cbor['protected'][4]);
        }

        if (empty($keyId)) {
            throw new \InvalidArgumentException('Invalid KID');
        }

        foreach ($certificates as $kid => $data) {
            if ($keyId == $kid)
                return $data;
        }

        // If no public key is found, throw an exception
        throw new \InvalidArgumentException('Public key not found in list');
        
    }

    public static function qrcode(string $qrcode)
    {
        if (! substr($qrcode, 0, 4) === 'HC1:') {
            throw new \InvalidArgumentException('Invalid HC1 Header');
        }
        $zlib = static::base45(substr($qrcode, 4));
        $cose = static::cose(static::zlib($zlib));
        $cbor = static::cbor($cose);

        $certificateKeys = array();

        $current_dir = dirname(__FILE__);

        $pem = "";

        if (static::GET_CERTIFICATE_FROM == static::LIST) {
            $uri = "$current_dir/../../assets/it-gov-dgc.json";
            $certs_obj = "";
            $is_file_expired = time() - filemtime($uri) > static::HOUR_BEFORE_DOWNLOAD_LIST * 3600;
            if ($is_file_expired) {
                $certificates = static::retrieveCertificateFromList($certificateKeys);
                if(!empty($certificates)){
                    $fp = fopen($uri, 'w');
                    $json_certs = json_encode($certificates);
                    fwrite($fp, $json_certs);
                    fclose($fp);
                    $certs_obj = json_decode($json_certs);
                } else {
                    throw new \Exception('Invalid certificates list');
                }
            } else {
                $fp = fopen($uri, 'r');
                $certs_obj = json_decode(fread($fp, filesize($uri)));
                fclose($fp);
            }
            $signingCertificate = static::validateKidList($cbor, $certs_obj);
            $pem = chunk_split($signingCertificate, 64, PHP_EOL);
        }
        if (static::GET_CERTIFICATE_FROM == static::JSON) {
            $certificates = static::retrieveCertificates();
            $signingCertificate = static::validateKidCountry($cbor, $certificates);
            $pem = chunk_split($signingCertificate['rawData'], 64, PHP_EOL);
        }

        // We convert the raw data into a PEM encoded certificate
        $pem = '-----BEGIN CERTIFICATE-----' . PHP_EOL . $pem . '-----END CERTIFICATE-----' . PHP_EOL;

        // The object is the data that should have been signed
        $structure = new ListObject();
        $structure->add(new TextStringObject('Signature1'));
        $structure->add($cose->get(0));
        $structure->add(new ByteStringObject(''));
        $structure->add($cose->get(2));

        // COnverted signature
        $derSignature = ECSignature::toAsn1($cbor['signature'], 64);

        // We verify the signature with the data structure and the PEM encoded key
        // If valid, the result is 1
        $isValid = 1 === openssl_verify((string) $structure, $derSignature, $pem, 'sha256');
        if (! $isValid) {
            while ($m = openssl_error_string()) {
                static::dump("OpenSSL Error ", $m);
            }
            throw new \InvalidArgumentException('The signature is NOT valid');
        }

        return new GreenPass($cbor['data'][- 260][1]);
    }

    private static function dump($title, $list)
    {
        echo "<h1>$title</h1><pre>" . print_r($list, true) . "</pre>";
    }
}
