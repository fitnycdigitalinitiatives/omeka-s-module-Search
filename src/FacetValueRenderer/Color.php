<?php

namespace Search\FacetValueRenderer;

use Laminas\View\Renderer\PhpRenderer;

class Color implements FacetValueRendererInterface
{
    public function getLabel(): string
    {
        return 'Color'; // @translate
    }

    public function render(PhpRenderer $view, string $value): string
    {
        if (preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            if (!str_starts_with($value, "#")) {
                $value = '#' . $value;
            }
            return "<div class='color-swatch' style='width:1em;height:1em;background-color:$value' aria-label='Color swatch of value $value' title='Color value: $value'></div>";
        } else {
            return $view->escapeHtml($value);
        }
    }
}
