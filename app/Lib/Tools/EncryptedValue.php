<?php
App::uses('BetterSecurity', 'Tools');

/**
 * Class for ondemand encryption of JSON serialized value
 */
class EncryptedValue implements JsonSerializable
{
    const ENCRYPTED_MAGIC = "\x1F\x1D";

    /** @var string */
    private $value;

    /** @var bool */
    private $isJson;

    public function __construct($value, $isJson = false)
    {
        $this->value = $value;
        $this->isJson = $isJson;
    }

    /**
     * @return mixed
     * @throws JsonException
     * @throws Exception
     */
    public function decrypt()
    {
        $decrypt = BetterSecurity::decrypt(substr($this->value, 2), Configure::read('Security.encryption_key'));
        return $this->isJson ? JsonTool::decode($decrypt) : $decrypt;
    }

    public function __toString()
    {
        return $this->decrypt();
    }

    public function jsonSerialize()
    {
        return $this->decrypt();
    }

    /**
     * Encrypt provided string if encryption is enabled. If not enabled, input value will be returned.
     * @param string $value
     * @return string
     * @throws Exception
     */
    public static function encryptIfEnabled($value)
    {
        $key = Configure::read('Security.encryption_key');
        if ($key) {
            return EncryptedValue::ENCRYPTED_MAGIC . BetterSecurity::encrypt($value, $key);
        }
        return $value;
    }

    /**
     * Check if value is encrypted (starts with encrypted magic)
     * @param string $value
     * @return bool
     */
    public static function isEncrypted($value)
    {
        return substr($value, 0, 2) === EncryptedValue::ENCRYPTED_MAGIC;
    }
}
