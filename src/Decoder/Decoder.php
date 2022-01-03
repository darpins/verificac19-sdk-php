<?php
namespace Herald\GreenPass\Decoder;

use CBOR\ByteStringObject;
use CBOR\ListObject;
use CBOR\StringStream;
use CBOR\TextStringObject;
use CBOR\OtherObject\OtherObjectManager;
use CBOR\Tag\TagManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\PS256;
use Cose\Key\Ec2Key;
use Cose\Key\Key;
use Cose\Key\RsaKey;
use Herald\GreenPass\GreenPass;
use Herald\GreenPass\Exceptions\NoCertificateListException;
use Herald\GreenPass\Utils\FileUtils;

class Decoder
{

    const LIST = 'list';

    const JSON = 'json';

    const GET_CERTIFICATE_FROM = 'list';

    // https://github.com/ehn-dcc-development/hcert-spec/blob/main/hcert_spec.md#332-signature-algorithm
    public const SUPPORTED_ALGO = [
        ES256::ID,
        PS256::ID
    ];

    private static function base45($base45)
    {
        try {
            $decoder = new \Herald\GreenPass\Decoder\Base45();

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

        $tagObjectManager = new TagManager();
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
        $cborDecoder = new \CBOR\Decoder(new TagManager(), new OtherObjectManager());

        $h1 = $list->get(0); // The first item corresponds to the protected header
        $headerStream = new StringStream($h1->getValue()); // The first item is also a CBOR encoded byte string
        $decoded['protected'] = $cborDecoder->decode($headerStream)->normalize(); // The array [1 => "-7"] = ["alg" => "ES256"]

        $h2 = $list->get(1); // The second item corresponds to unprotected header
        $decoded['unprotected'] = $h2->normalize(); // The index 4 refers to the 'kid' (key ID) parameter (see https://www.iana.org/assignments/cose/cose.xhtml)

        $data = $list->get(2); // The third item corresponds to the data we want to load
        if (! $data instanceof ByteStringObject) {
            throw new \InvalidArgumentException('Not a valid certificate. The payload is not a byte string.');
        }
        $infoStream = new StringStream($data->getValue()); // The third item is a CBOR encoded byte string
        $decoded['data'] = $cborDecoder->decode($infoStream)->normalize(); // The data we are looking for

        $signature = $list->get(3); // The fourth item is the signature.
                                    // It can be verified using the protected header (first item) and the data (third item)
                                    // And the public key
        if (! $signature instanceof ByteStringObject) {
            throw new \InvalidArgumentException('Not a valid certificate. The signature is not a byte string.');
        }
        $decoded['signature'] = $signature->normalize(); // The digital signature

        return $decoded;
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
            if (false !== ($matches = array_pad(explode(':', $value), 2, null))) {
                $headers_arr["{$matches[0]}"] = trim($matches[1]);
            }
        }

        if (empty($info['http_code'])) {
            throw new \InvalidArgumentException("No HTTP code was returned");
        }

        if ($info['http_code'] == 200) {
            $list[$headers_arr['X-KID']] = $body;
            return static::retrieveCertificateFromList($list, $headers_arr['X-RESUME-TOKEN']);
        } else {
            return $list;
        }
    }

    // Retrieve from RESUME-TOKEN KID
    private static function retrieveCertificatesStatus()
    {
        // We retrieve the public keys
        $ch = curl_init('https://get.dgc.gov.it/v1/dgc/signercertificate/status');

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        // Then, after your curl_exec call:
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $header_size);

        if (empty($info['http_code'])) {
            throw new \InvalidArgumentException("No HTTP code was returned");
        }

        if ($info['http_code'] == 200) {
            return $body;
        }

        throw new NoCertificateListException("status");
    }

    private static function retrieveKidFromCBOR($cbor)
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

        return $keyId;
    }

    private static function retrieveAlgorithmFromCBOR($cbor)
    {
        $alg = "";

        if (is_array($cbor['unprotected']) && isset($cbor['unprotected'][1])) {
            if (! in_array($cbor['unprotected'][1], self::SUPPORTED_ALGO)) {
                throw new \InvalidArgumentException('Certificate algorithm with identifier ' . $cbor['unprotected'][1] . ' not supported');
            }
            $alg = $cbor['unprotected'][1];
        }

        if (is_array($cbor['protected']) && isset($cbor['protected'][1])) {
            if (! in_array($cbor['protected'][1], self::SUPPORTED_ALGO)) {
                throw new \InvalidArgumentException('Certificate algorithm with identifier ' . $cbor['protected'][1] . ' not supported');
            }
            $alg = $cbor['protected'][1];
        }

        if (empty($alg)) {
            throw new \InvalidArgumentException('Invalid algorithm');
        }

        return $alg;
    }

    private static function validateKidList($keyId, $certificates)
    {
        foreach ($certificates as $kid => $data) {
            if ($keyId == $kid) {
                return $data;
            }
        }

        // If no public key is found, throw an exception
        throw new \InvalidArgumentException('Public key not found in list');
    }

    public static function qrcode(string $qrcode)
    {
        if (substr($qrcode, 0, 4) === 'HC1:') {
            $qrcode = substr($qrcode, 4);
        }
        $zlib = static::base45($qrcode);
        $cose = static::cose(static::zlib($zlib));
        $cbor = static::cbor($cose);

        $certificateKeys = array();

        $pem = "";

        $alg = static::retrieveAlgorithmFromCBOR($cbor);
        $keyId = static::retrieveKidFromCBOR($cbor);

        if (static::GET_CERTIFICATE_FROM == static::LIST) {

            $locale = FileUtils::COUNTRY;

            // Check if kid in certificate list status
            $uri_status = FileUtils::getCacheFilePath("$locale-gov-dgc-status.json");
            $certs_list = "";

            if (FileUtils::checkFileNotExistOrExpired($uri_status, FileUtils::HOUR_BEFORE_DOWNLOAD_LIST * 3600)) {
                $certificate_status = static::retrieveCertificatesStatus();
                FileUtils::saveDataToFile($uri_status, $certificate_status);
                $certs_list = json_decode($certificate_status);
            } else {
                $certs_list = json_decode(FileUtils::readDataFromFile($uri_status));
            }

            if (! in_array($keyId, $certs_list)) {
                throw new \InvalidArgumentException('Public key not found list');
            }

            // Check if kid in certificate list status
            $uri = FileUtils::getCacheFilePath("$locale-gov-dgc-certs.json");
            $certificates = "";

            if (FileUtils::checkFileNotExistOrExpired($uri, FileUtils::HOUR_BEFORE_DOWNLOAD_LIST * 3600)) {
                $certificates = static::retrieveCertificateFromList($certificateKeys);
                if (! FileUtils::saveDataToFile($uri, json_encode($certificates))) {
                    throw new NoCertificateListException("update");
                }
            } else {
                $certificates = json_decode(FileUtils::readDataFromFile($uri));
            }

            $signingCertificate = static::validateKidList($keyId, $certificates);
            $pem = chunk_split($signingCertificate, 64, PHP_EOL);
        }

        // We convert the raw data into a PEM encoded certificate
        $pem = '-----BEGIN CERTIFICATE-----' . PHP_EOL . $pem . '-----END CERTIFICATE-----' . PHP_EOL;

        $publicKey = null;
        $publicKeyData = null;

        try {
            $cert = openssl_x509_read($pem);
            $publicKey = openssl_pkey_get_public($cert);
            $publicKeyData = openssl_pkey_get_details($publicKey);
            
        } catch (\Exception $exception) {
            throw new \InvalidArgumentException('Failed to parse cert data');
        }

        if (ES256::ID == $alg) {
            $verifier = new ES256();
            $key = Key::createFromData([ // ECDSA
                Key::TYPE => Key::TYPE_EC2,
                Key::KID => $keyId,
                Ec2Key::DATA_CURVE => Ec2Key::CURVE_P256,
                Ec2Key::DATA_X => $publicKeyData['ec']['x'],
                Ec2Key::DATA_Y => $publicKeyData['ec']['y']
            ]);
        }
        if (PS256::ID == $alg) {
            $verifier = new PS256();
            $key = Key::createFromData([
                Key::TYPE => Key::TYPE_RSA,
                Key::KID => $keyId,
                RsaKey::DATA_E => $publicKeyData['rsa']['e'],
                RsaKey::DATA_N => $publicKeyData['rsa']['n']
            ]);
        }

        // The object is the data that should have been signed
        $structure = new ListObject();
        $structure->add(new TextStringObject('Signature1'));
        $structure->add($cose->get(0));
        $structure->add(new ByteStringObject(''));
        $structure->add($cose->get(2));

        // We verify the signature with the data structure and the PEM encoded key
        if (! $verifier->verify((string) $structure, $key, $cbor['signature'])) {
            throw new \InvalidArgumentException("The signature is NOT valid");
        }

        return new GreenPass($cbor['data'][- 260][1], $pem);
    }
}
