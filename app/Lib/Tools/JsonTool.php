<?php
class JsonTool
{
    /**
     * @param mixed $value
     * @param bool $prettyPrint
     * @returns string
     * @throws JsonException
     */
    public static function encode($value, $prettyPrint = false)
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags);
    }

    /**
     * @param string $value
     * @returns mixed
     * @throws JsonException
     * @throws UnexpectedValueException
     */
    public static function decode($value)
    {
        if (function_exists('simdjson_decode')) {
            // Use faster version of json_decode from simdjson PHP extension if this extension is installed
            try {
                return simdjson_decode($value, true);
            } catch (SimdJsonException $e) {
                throw new JsonException($e->getMessage(), $e->getCode(), $e);
            }
        }
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $value
     * @return array
     * @throws JsonException
     */
    public static function decodeArray($value)
    {
        $decoded = self::decode($value);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('JSON must be array type, get ' . gettype($decoded));
        }
        return $decoded;
    }

    /**
     * Check if string is valid JSON
     * @param string $value
     * @return bool
     */
    public static function isValid($value)
    {
        if (function_exists('simdjson_is_valid')) {
            return simdjson_is_valid($value);
        }

        try {
            self::decode($value);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @see https://www.php.net/manual/en/function.array-is-list.php
     * @param array $array
     * @return bool
     */
    public static function arrayIsList(array $array)
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        $i = -1;
        foreach ($array as $k => $v) {
            ++$i;
            if ($k !== $i) {
                return false;
            }
        }
        return true;
    }

    /**
     * JSON supports just unicode strings. This helper method converts non unicode chars to Unicode Replacement Character U+FFFD (UTF-8)
     * @param string $string
     * @return string
     */
    public static function escapeNonUnicode($string)
    {
        if (mb_check_encoding($string, 'UTF-8')) {
            return $string; // string is valid unicode
        }

        return htmlspecialchars_decode(htmlspecialchars($string, ENT_SUBSTITUTE, 'UTF-8'));
    }
}
