<?php

namespace Search\Job\OcrConverter;

class BoxType
{
    const PAGE = 1;
    const BLOCK = 2;
    const LINE = 3;
    const WORD = 4;

    public static function fromHocrClass($val)
    {
        return match ($val) {
            "ocr_page" => self::PAGE,
            "ocr_carea", "ocr_par", "ocrx_block" => self::BLOCK,
            "ocr_line" => self::LINE,
            "ocrx_word" => self::WORD,
            default => null
        };
    }

    public static function fromAltoTag($val)
    {
        return match ($val) {
            "Page" => self::PAGE,
            "PrintSpace", "TextBlock" => self::BLOCK,
            "TextLine" => self::LINE,
            "String" => self::WORD,
            default => null
        };
    }

    public static function toMiniocrTag($val)
    {
        return match ($val) {
            self::PAGE => "p",
            self::BLOCK => "b",
            self::LINE => "l",
            self::WORD => "w"
        };
    }
}
