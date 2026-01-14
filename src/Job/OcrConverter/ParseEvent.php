<?php

namespace Search\Job\OcrConverter;

// Simple equivalent of Python's dataclass
class ParseEvent
{
    private $kind;
    private $boxType;
    private $pageId;
    private $x;
    private $y;
    private $width;
    private $height;
    private $text;

    public function __construct()
    {
        $this->kind = null;
        $this->boxType = null;
        $this->pageId = null;
        $this->x = null;
        $this->y = null;
        $this->width = null;
        $this->height = null;
        $this->text = null;
    }

    public function setKind($kind)
    {
        $this->kind = $kind;
    }
    public function getKind()
    {
        return $this->kind;
    }
    public function setBoxType($boxType)
    {
        $this->boxType = $boxType;
    }
    public function getBoxType()
    {
        return $this->boxType;
    }

    public function setPageId($pageId)
    {
        $this->pageId = $pageId;
    }
    public function getPageId()
    {
        return $this->pageId;
    }
    public function setX($x)
    {
        $this->x = $x;
    }
    public function getX()
    {
        return $this->x;
    }
    public function setY($y)
    {
        $this->y = $y;
    }
    public function getY()
    {
        return $this->y;
    }
    public function setWidth($width)
    {
        $this->width = $width;
    }
    public function getWidth()
    {
        return $this->width;
    }
    public function setHeight($height)
    {
        $this->height = $height;
    }
    public function getHeight()
    {
        return $this->height;
    }
    public function setText($text)
    {
        $this->text = $text;
    }
    public function getText()
    {
        return $this->text;
    }
}
