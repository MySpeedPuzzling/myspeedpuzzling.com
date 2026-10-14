<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use SpeedPuzzling\Web\Services\Mailer\Sns\SnsMessageVerifier;

/**
 * A throwaway RSA key + self-signed certificate standing in for Amazon SNS's signing certificate
 */
trait SignsSnsMessages
{
    private const string TEST_TOPIC_ARN = 'arn:aws:sns:eu-central-1:000000000000:msp-ses-events-test';
    private const string TEST_CERTIFICATE_URL = 'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.pem';

    private static null|\OpenSSLAsymmetricKey $snsTestKey = null;
    private static string $snsTestCertificate = '';

    private static function snsTestCertificate(): string
    {
        self::snsTestKey();

        return self::$snsTestCertificate;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function signedSnsMessage(array $fields, string $signatureVersion = '2'): array
    {
        $message = $fields + [
            'MessageId' => 'f5b3a1c2-0000-4000-8000-' . bin2hex(random_bytes(6)),
            'TopicArn' => self::TEST_TOPIC_ARN,
            'Timestamp' => '2026-10-12T20:00:00.000Z',
            'SignatureVersion' => $signatureVersion,
            'SigningCertURL' => self::TEST_CERTIFICATE_URL,
        ];

        openssl_sign(
            SnsMessageVerifier::stringToSign($message),
            $signature,
            self::snsTestKey(),
            $signatureVersion === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256,
        );
        assert(is_string($signature));
        $message['Signature'] = base64_encode($signature);

        return $message;
    }

    /**
     * @param array<string, mixed> $sesEvent
     *
     * @return array<string, mixed>
     */
    private static function signedSesNotification(array $sesEvent): array
    {
        return self::signedSnsMessage([
            'Type' => 'Notification',
            'Message' => json_encode($sesEvent, JSON_THROW_ON_ERROR),
        ]);
    }

    private static function snsTestKey(): \OpenSSLAsymmetricKey
    {
        if (self::$snsTestKey !== null) {
            return self::$snsTestKey;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        assert($key instanceof \OpenSSLAsymmetricKey);
        // openssl_csr_new() takes the key by reference
        $csrKey = $key;
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $csrKey);
        assert($csr instanceof \OpenSSLCertificateSigningRequest);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        assert($certificate instanceof \OpenSSLCertificate);
        openssl_x509_export($certificate, $pem);
        assert(is_string($pem));

        self::$snsTestKey = $key;
        self::$snsTestCertificate = $pem;

        return $key;
    }
}
