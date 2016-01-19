<?php
namespace App\View\Helper;

use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\View\Helper;

/**
 * Class AjaxHelper
 * @package App\View\Helper
 * @property Helper\HtmlHelper $Html
 * @property Helper\FormHelper $Form
 */
class AjaxHelper extends Helper
{

    private $inBlock = false;
    public $useNative = false;
    public $enabled = true;
    public $safe = false;
    public $cacheEvents = false;
    public $cacheToFile = false;
    public $cacheAll = false;
    public $scriptBuffer = null;
    public $tags = [
        'javascriptStart' => '<script type="text/javascript">',
        'javascriptEnd' => '</script>',
        'javascriptBlock' => '<script type="text/javascript">%s</script>',
        'javascriptLink' => '<script type="text/javascript" src="%s"></script>'
    ];
    public $blockOptions = [];
    public $cachedEvents = [];
    public $ajaxBuffer = [];
    public $rules = [];
    public $helpers = ['Html', 'Form'];
    public $callbacks = ['beforeSend', 'complete', 'error', 'success', 'beforeSubmit'];
    public $ajaxOptions = ['async', 'beforeSend', 'cache', 'complete', 'contentType', 'data', 'dataType', 'error', 'global', 'isModified', 'jsonp', 'password', 'processData', 'success', 'timeout', 'type', 'url', 'name', 'target', 'beforeSubmit'];
    public $ajaxFormOptions = ['target', 'beforeSubmit', 'semantic', 'resetFrom', 'clearForm'];
    public $editorOptions = ['id', 'name', 'loadurl', 'type', 'data', 'style', 'callback', 'submitdata', 'method', 'rows', 'cols', 'width', 'loadtype', 'loaddata', 'onblur', 'cancel', 'submit', 'tooltip', 'placeholder', 'ajaxOptions'];
    public $ajaxFormCallbacks = ['beforeSubmit'];

    /**
     * @param string $title
     * @param string $href
     * @param array $options
     * @param null $confirm
     * @return string
     */
    public function link($title, $href = null, $options = [], $confirm = null)
    {
        if (!isset($href)) {
            $href = $title;
        }

        if (!isset($options['url'])) {
            $options['url'] = $href;
        }

        if (isset($confirm)) {
            $options['confirm'] = $confirm;
            unset($confirm);
        }

        $htmlOptions = $this->getHtmlOptions($options);
        if (isset($htmlOptions['confirm'])) {
            unset($htmlOptions['confirm']);
        }

        if (!isset($htmlOptions['id'])) {
            $htmlOptions['id'] = 'link' . intval(rand());
        }

        if (!isset($htmlOptions['onclick'])) {
            $htmlOptions['onclick'] = '';
        }

        $htmlOptions['onclick'] .= ' return false;';
        $return = $this->Html->link($title, '#', $htmlOptions);
        $script = $this->codeBlock(
            "jQuery('#{$htmlOptions['id']}').click( function() {" . $this->remoteFunction($options) . "; return false;});"
        );

        if (is_string($script)) {
            $return .= $script;
        }
        return $return;
    }

    /**
     * Creates JavaScript function for remote AJAX call
     * @param array $options options for javascript
     * @param string $function
     * @return string html code for link to remote action
     */
    public function remoteFunction($options = null, $function = 'jQuery.ajax')
    {
        if (isset($options['confirm'])) {
            $confirm = $options['confirm'];
            unset($options['confirm']);
        }
        $func = "{$function}(" . $this->optionsForAjax($options) . ")";

        if (isset($options['before'])) {
            $func = "{$options['before']}; $func";
        }

        if (isset($options['after'])) {
            $func = "$func; {$options['after']};";
        }

        if (isset($options['condition'])) {
            $func = "if ({$options['condition']}) { $func; }";
        }

        if (isset($confirm)) {
            $func = "if (confirm('" . $this->escapeString($confirm)
                . "')) { $func; } else { event.returnValue = false; return false; }";
        }
        return $func;
    }

    /**
     * Returns a button input tag that will submit form using XMLHttpRequest in the background instead of regular
     * reloading POST arrangement. <i>options</i> argument is the same as in <i>form_remote_tag</i>
     * @param string $title Input button title
     * @param array $options Callback options
     * @return string Ajax'ed input button
     */
    public function submit($title = 'Submit', $options = [])
    {
        $htmlOptions = $this->getHtmlOptions($options);
        $htmlOptions['value'] = $title;

        if (!isset($htmlOptions['id'])) {
            $htmlOptions['id'] = 'submit' . intval(rand());
        }

        $htmlOptions['onclick'] = "return false;";

        return $this->Form->submit($title, $htmlOptions)
        . $this->codeBlock("jQuery('#{$htmlOptions['id']}').click( function() { " . $this->remoteFunction($options, "jQuery('#{$htmlOptions['id']}').parents('form').ajaxSubmit") . "; return false;});");
    }

    /**
     * Returns form tag that will submit using Ajax.
     *
     * Returns a form tag that will submit using XMLHttpRequest in the background instead of the regular
     * reloading POST arrangement. Even though it's using Javascript to serialize the form elements,
     * the form submission will work just like a regular submission as viewed by the receiving side
     * (all elements available in params).  The options for defining callbacks is the same
     * as AjaxHelper::link().
     *
     * @param mixed $params Either a string identifying the form target, or an array of method
     *                      parameters, including:
     *                          - 'params' => Acts as the form target
     *                          - 'type' => 'post' or 'get'
     *                          - 'options' => An array containing all HTML and script options used to
     *                             generate the form tag and Ajax request.
     * @param string $type How form data is posted: 'get' or 'post'
     * @param array $options Callback/HTML options
     * @return string JavaScript/HTML code
     * @see AjaxHelper::link()
     */
    public function form($params = null, $type = 'post', $options = [])
    {
        $model = false;
        if (is_array($params)) {
            extract($params, EXTR_OVERWRITE);
        }

        $htmlDefaults = [
            'id' => 'form' . intval(mt_rand()),
            'onsubmit' => "return false;",
            'type' => $type
        ];
        $htmlOptions = $this->getHtmlOptions($options, ['model', 'with']);
        $htmlOptions = array_merge($htmlDefaults, $htmlOptions);
        $htmlOptions['url'] = extract($options, 'url');

        $defaults = ['model' => $model];
        $options = array_merge($defaults, $options);
        $form = $this->Form->create($options['model'], $htmlOptions);
        $script = $this->codeBlock("jQuery('#{$htmlOptions['id']}').submit( function() { " . $this->remoteFunction($options, "jQuery('#{$htmlOptions['id']}').ajaxSubmit") . "; return false;});");
        return $form . $script;
    }

    /**
     * Makes an Ajax In Place editor control.
     * @param string $id DOM ID of input element
     * @param string $url Post back URL of saved data
     * @param array $options Array of options to control the editor, including ajaxOptions
     * @return string
     */
    public function editor($id, $url, $options = [])
    {
        $this->link('jquery/jquery.jeditable.mini', false);
        $url = $this->url($url);
        $options = $this->optionsToString($options, [
            'id', 'name', 'loadurl', 'type', 'data', 'style', 'callback', 'submitdata', 'method', 'rows', 'cols', 'width', 'loadtype', 'loaddata', 'onblur', 'cancel', 'submit', 'tooltip', 'placeholder',
        ]);
        $options = $this->buildOptions($options, $this->editorOptions);
        $script = "jQuery('#{$id}').editable('{$url}', {$options});";
        return $this->codeBlock($script);
    }

    /**
     * @param $field
     * @param array $options
     * @return string
     */
    public function observeField($field, $options = [])
    {
        return $this->codeBlock("jQuery('#{$field}').change( function() { " . $this->remoteFunction($options, "jQuery('#{$field}').parents('form').ajaxSubmit") . "; return false;});");
    }

    /**
     * Creates an Ajax DIV element
     * @param $id
     * @param array $options
     * @return string
     */
    public function div($id, $options = [])
    {
        if (env('HTTP_X_UPDATE') != null) {
            $this->enabled = false;
            $divs = explode(' ', env('HTTP_X_UPDATE'));

            if (in_array($id, $divs)) {
                @ob_end_clean();
                ob_start();
                return '';
            }
        }
        $attr = $this->parseAttributes(array_merge($options, ['id' => $id]));
        return sprintf($this->Html->tags['blockstart'], $attr);
    }

    /**
     * Closes an Ajax DIV element
     *
     * @param string $id The DOM ID of the element
     * @return string HTML code
     */
    public function divEnd($id)
    {
        if (env('HTTP_X_UPDATE') != null) {
            $divs = explode(' ', env('HTTP_X_UPDATE'));
            if (in_array($id, $divs)) {
                $this->ajaxBuffer[$id] = ob_get_contents();
                ob_end_clean();
                ob_start();
                return '';
            }
        }
        return $this->Html->tags['blockend'];
    }

    /**
     * Private helper function for Javascript.
     * @param array $options
     * @return string
     */
    private function optionsForAjax($options = [])
    {
        if (isset($options['update'])) {
            $update = $options['update'];
            if (is_array($options['update'])) {
                $update = join(' ', $options['update']);
            }
            $options['beforeSend'] = "request.setRequestHeader('X-Update', '{$update}');";
            if (!isset($options['success'])) {
                $options['success'] = '';
            }
            $options['success'] = "jQuery('#{$options['update']}').html(data);" . $options['success'];
        }

        if (isset($options['url'])) {
            $options['url'] = $this->url($options['url'], $options);
        }

        if (isset($options['indicator'])) {
            if (isset($options['loading'])) {
                if (!empty($options['loading']) && substr(trim($options['loading']), -1, 1) != ';') {
                    $options['loading'] .= '; ';
                }
                $options['loading'] .= "jQuery('#{$options['indicator']}').show();";
            } else {
                $options['loading'] = "jQuery('#{$options['indicator']}').show();";
            }
            if (isset($options['complete'])) {
                if (!empty($options['complete']) && substr(trim($options['complete']), -1, 1) != ';') {
                    $options['complete'] .= '; ';
                }
                $options['complete'] .= "jQuery('#{$options['indicator']}').hide();";
            } else {
                $options['complete'] = "jQuery('#{$options['indicator']}').hide();";
            }
            unset($options['indicator']);
        }
        if (isset($options['loading'])) {
            if (!isset($options['beforeSend'])) {
                $options['beforeSend'] = '';
            }
            $options['beforeSend'] .= $options['loading'];
            unset($options['loading']);
        }
        $options = self::am(['async' => true, 'type' => 'post'], $options);
        $options = $this->optionsToString($options, ['async', 'contentType', 'dataType', 'jsonp', 'password', 'type', 'url', 'name', 'target']);
        $jsOptions = $this->buildCallbacks($options);
        $jsOptions = array_merge($jsOptions, array_intersect_key($options, array_flip(['async', 'cache', 'contentType', 'data', 'dataType', 'global', 'isModified', 'jsonp', 'password', 'processData', 'timeout', 'type', 'url', 'name', 'target'])));
        return $this->buildOptions($jsOptions, $this->ajaxOptions);
    }

    /**
     * Private Method to return a string of html options
     * option data as a JavaScript options hash.
     *
     * @param array $options Options in the shape of keys and values
     * @param array $extra Array of legal keys in this options context
     * @return array Array of html options
     */
    private function getHtmlOptions($options, $extra = [])
    {
        foreach ($this->ajaxOptions as $key) {
            if (isset($options[$key])) {
                unset($options[$key]);
            }
        }

        foreach ($extra as $key) {
            if (isset($options[$key])) {
                unset($options[$key]);
            }
        }

        return $options;
    }

    /**
     * Returns a string of JavaScript with the given option data as a JavaScript options hash.
     *
     * @param array $options Options in the shape of keys and values
     * @param array $acceptable Array of legal keys in this options context
     * @return string    String of Javascript array definition
     */
    public function buildOptions($options, $acceptable)
    {
        if (is_array($options)) {
            $out = [];

            foreach ($options as $k => $v) {
                if (in_array($k, $acceptable)) {
                    if ($v === true) {
                        $v = 'true';
                    } elseif ($v === false) {
                        $v = 'false';
                    }
                    $out[] = "$k:$v";
                }
            }

            $out = join(', ', $out);
            $out = '{' . $out . '}';
            return $out;
        } else {
            return false;
        }
    }

    /**
     * Return Javascript text for callbacks.
     *
     * @param array $options Option array where a callback is specified
     * @return array Options with their callbacks properly set
     */
    protected function buildCallbacks($options)
    {
        $callbacks = [];

        foreach ($this->callbacks as $callback) {
            if (isset($options[$callback])) {
                $code = $options[$callback];
                switch ($callback) {
                    case 'beforeSend':
                        $callbacks[$callback] = "function(request) {" . $code . "}";
                        break;
                    case 'complete':
                        $callbacks[$callback] = "function(request, textStatus) {" . $code . "}";
                        break;
                    case 'error':
                        $callbacks[$callback] = "function(request, textStatus, errorThrown) {" . $code . "}";
                        break;
                    case 'success':
                        $callbacks[$callback] = "function(data, textStatus) {" . $code . "}";
                        break;
                    case 'beforeSubmit':
                        $callbacks[$callback] = "function() {" . $code . "}";
                        break;
                    default:
                        $callbacks[$callback] = "function(request) {" . $code . "}";
                        break;
                }

            }
        }
        return $callbacks;
    }

    /**
     * Returns a string of JavaScript with a string representation of given options array.
     *
     * @param array $options Ajax options array
     * @param array $stringOpts Options as strings in an array
     * @return array
     */
    private function optionsToString($options, $stringOpts = [])
    {
        foreach ($stringOpts as $option) {
            if (isset($options[$option]) /*&& !empty($options[$option]) && is_string($options[$option]) && $options[$option][0] != "'"*/) {
                if ($options[$option] === true || $options[$option] === 'true') {
                    $options[$option] = 'true';
                } elseif ($options[$option] === false || $options[$option] === 'false') {
                    $options[$option] = 'false';
                } elseif (strpos($options[$option], '{') === 0) {

                } elseif (strpos($options[$option], 'function(') === 0) {

                } else {
                    $options[$option] = "'{$options[$option]}'";
                }
            }
        }
        return $options;
    }

    /**
     * Executed after a view has rendered, used to include buffered code blocks.
     */
    public function afterRender()
    {
        if (env('HTTP_X_UPDATE') != null && !empty($this->ajaxBuffer)) {
            @ob_end_clean();

            $data = [];
            $divs = explode(' ', env('HTTP_X_UPDATE'));
            $keys = array_keys($this->ajaxBuffer);

            if (count($divs) == 1 && in_array($divs[0], $keys)) {
                echo($this->ajaxBuffer[$divs[0]]);
            } else {
                foreach ($this->ajaxBuffer as $key => $val) {
                    if (in_array($key, $divs)) {
                        $data[] = $key . ':"' . rawurlencode($val) . '"';
                    }
                }
                $out = 'var __ajaxUpdater__ = {' . join(", \n", $data) . '};' . "\n";
                $out .= 'for (n in __ajaxUpdater__) { if (typeof __ajaxUpdater__[n] == "string" && jQuery(n)) jQuery(\'#n\').html(unescape(decodeURIComponent(__ajaxUpdater__[n])));}';

                echo($this->codeBlock($out, false));
            }
            $scripts = $this->getCache();

            if (!empty($scripts)) {
                echo($this->codeBlock($scripts, false));
            }
            exit();
        }
    }

    public static function am()
    {
        $r = [];
        $args = func_get_args();
        foreach ($args as $a) {
            if (!is_array($a)) {
                $a = [$a];
            }
            $r = array_merge($r, $a);
        }
        return $r;
    }

    private function parseAttributes($options, $exclude = null, $insertBefore = ' ', $insertAfter = null)
    {
        if (is_array($options)) {
            if (!is_array($exclude)) {
                $exclude = [];
            }
            $keys = array_diff(array_keys($options), array_merge($exclude, ['escape']));
            $values = array_intersect_key(array_values($options), $keys);
            $escape = $options['escape'];
            $attributes = [];

            foreach ($keys as $index => $key) {
                if ($values[$index] !== false && $values[$index] !== null) {
                    $attributes[] = $this->formatAttribute($key, $values[$index], $escape);
                }
            }
            $out = implode(' ', $attributes);
        } else {
            $out = $options;
        }
        return $out ? $insertBefore . $out . $insertAfter : '';
    }

    /**
     * Formats an individual attribute, and returns the string value of the composed attribute.
     * Works with minimized attributes that have the same value as their name such as 'disabled' and 'checked'
     *
     * @param string $key The name of the attribute to create
     * @param string $value The value of the attribute to create.
     * @param bool $escape
     * @return string The composed attribute.
     * @access private
     */
    private function formatAttribute($key, $value, $escape = true)
    {
        $attribute = '';
        $attributeFormat = '%s="%s"';
        $minimizedAttributes = ['compact', 'checked', 'declare', 'readonly', 'disabled',
            'selected', 'defer', 'ismap', 'nohref', 'noshade', 'nowrap', 'multiple', 'noresize'];
        if (is_array($value)) {
            $value = '';
        }

        if (in_array($key, $minimizedAttributes)) {
            if ($value === 1 || $value === true || $value === 'true' || $value === '1' || $value == $key) {
                $attribute = sprintf($attributeFormat, $key, $key);
            }
        } else {
            $attribute = sprintf($attributeFormat, $key, ($escape ? h($value) : $value));
        }
        return $attribute;
    }

    public function url($url = null, $options)
    {
        $url = Router::url($url);
        if (isset($options['escape']) && $options['escape'] === false) {
            return $url;
        }
        return h($url);
    }

    /**
     * Returns a JavaScript script tag.
     *
     * Options:
     *
     *  - allowCache: boolean, designates whether this block is cacheable using the
     * current cache settings.
     *  - safe: boolean, whether this block should be wrapped in CDATA tags.  Defaults
     * to helper's object configuration.
     *  - inline: whether the block should be printed inline, or written
     * to cached for later output (i.e. $scripts_for_layout).
     *
     * @param string $script The JavaScript to be wrapped in SCRIPT tags.
     * @param array $options Set of options:
     * @return string The full SCRIPT element, with the JavaScript inside it, or null,
     *   if 'inline' is set to false.
     */
    public function codeBlock($script = null, $options = [])
    {
        if (!empty($options) && !is_array($options)) {
            $options = ['allowCache' => $options];
        } elseif (empty($options)) {
            $options = [];
        }
        $defaultOptions = ['allowCache' => true, 'safe' => true, 'inline' => true];
        $options = array_merge($defaultOptions, $options);

        if (empty($script)) {
            $this->scriptBuffer = @ob_get_contents();
            $this->blockOptions = $options;
            $this->inBlock = true;
            @ob_end_clean();
            ob_start();
            return null;
        }
        if ($this->cacheEvents && $this->cacheAll && $options['allowCache']) {
            $this->cachedEvents[] = $script;
            return null;
        }
        if ($options['safe'] || $this->safe) {
            $script = "\n" . '//<![CDATA[' . "\n" . $script . "\n" . '//]]>' . "\n";
        }
        if ($options['inline']) {
            return sprintf($this->tags['javascriptBlock'], $script);
        }
        return '';
    }

    /**
     * Ends a block of cached JavaScript code
     *
     * @return mixed
     */
    public function blockEnd()
    {
        if (!isset($this->inBlock) || !$this->inBlock) {
            return null;
        }
        $script = @ob_get_contents();
        @ob_end_clean();
        ob_start();
        echo $this->scriptBuffer;
        $this->scriptBuffer = null;
        $options = $this->blockOptions;
        $this->blockOptions = [];
        $this->inBlock = false;

        if (empty($script)) {
            return null;
        }
        return $this->codeBlock($script, $options);
    }

    /**
     * Escape carriage returns and single and double quotes for JavaScript segments.
     *
     * @param string $script string that might have javascript elements
     * @return string escaped string
     */
    public function escapeScript($script)
    {
        $script = str_replace(["\r\n", "\n", "\r"], '\n', $script);
        $script = str_replace(['"', "'"], ['\"', "\\'"], $script);
        return $script;
    }

    /**
     * Escape a string to be JavaScript friendly.
     *
     * List of escaped ellements:
     *    + "\r\n" => '\n'
     *    + "\r" => '\n'
     *    + "\n" => '\n'
     *    + '"' => '\"'
     *    + "'" => "\\'"
     *
     * @param  string $string String that needs to get escaped.
     * @return string Escaped string.
     */
    public function escapeString($string)
    {
        $escape = ["\r\n" => "\n", "\r" => "\n"];
        $string = str_replace(array_keys($escape), array_values($escape), $string);
        return $this->utf8ToHex($string);
    }

    /**
     * Encode a string into JSON.  Converts and escapes necessary characters.
     * @param $string
     * @return string
     */
    private function utf8ToHex($string)
    {
        $length = strlen($string);
        $return = '';
        for ($i = 0; $i < $length; ++$i) {
            $ord = ord($string{$i});
            switch (true) {
                case $ord == 0x08:
                    $return .= '\b';
                    break;
                case $ord == 0x09:
                    $return .= '\t';
                    break;
                case $ord == 0x0A:
                    $return .= '\n';
                    break;
                case $ord == 0x0C:
                    $return .= '\f';
                    break;
                case $ord == 0x0D:
                    $return .= '\r';
                    break;
                case $ord == 0x22:
                case $ord == 0x2F:
                case $ord == 0x5C:
                case $ord == 0x27:
                    $return .= '\\' . $string{$i};
                    break;
                case (($ord >= 0x20) && ($ord <= 0x7F)):
                    $return .= $string{$i};
                    break;
                case (($ord & 0xE0) == 0xC0):
                    if ($i + 1 >= $length) {
                        $i += 1;
                        $return .= '?';
                        break;
                    }
                    $charbits = $string{$i} . $string{$i + 1};
                    $char = self::utf8($charbits);
                    $return .= sprintf('\u%04s', dechex($char[0]));
                    $i += 1;
                    break;
                case (($ord & 0xF0) == 0xE0):
                    if ($i + 2 >= $length) {
                        $i += 2;
                        $return .= '?';
                        break;
                    }
                    $charbits = $string{$i} . $string{$i + 1} . $string{$i + 2};
                    $char = self::utf8($charbits);
                    $return .= sprintf('\u%04s', dechex($char[0]));
                    $i += 2;
                    break;
                case (($ord & 0xF8) == 0xF0):
                    if ($i + 3 >= $length) {
                        $i += 3;
                        $return .= '?';
                        break;
                    }
                    $charbits = $string{$i} . $string{$i + 1} . $string{$i + 2} . $string{$i + 3};
                    $char = self::utf8($charbits);
                    $return .= sprintf('\u%04s', dechex($char[0]));
                    $i += 3;
                    break;
                case (($ord & 0xFC) == 0xF8):
                    if ($i + 4 >= $length) {
                        $i += 4;
                        $return .= '?';
                        break;
                    }
                    $charbits = $string{$i} . $string{$i + 1} . $string{$i + 2} . $string{$i + 3} . $string{$i + 4};
                    $char = self::utf8($charbits);
                    $return .= sprintf('\u%04s', dechex($char[0]));
                    $i += 4;
                    break;
                case (($ord & 0xFE) == 0xFC):
                    if ($i + 5 >= $length) {
                        $i += 5;
                        $return .= '?';
                        break;
                    }
                    $charbits = $string{$i} . $string{$i + 1} . $string{$i + 2} . $string{$i + 3} . $string{$i + 4} . $string{$i + 5};
                    $char = self::utf8($charbits);
                    $return .= sprintf('\u%04s', dechex($char[0]));
                    $i += 5;
                    break;
            }
        }
        return $return;
    }

    /**
     * Attach an event to an element. Used with the Prototype library.
     *
     * @param string $object Object to be observed
     * @param string $event event to observe
     * @param string $observer function to call
     * @param array $options Set options: useCapture, allowCache, safe
     * @return boolean true on success
     */
    public function event($object, $event, $observer = null, $options = [])
    {
        if (!empty($options) && !is_array($options)) {
            $options = ['useCapture' => $options];
        } else if (empty($options)) {
            $options = [];
        }

        $defaultOptions = ['useCapture' => false];
        $options = array_merge($defaultOptions, $options);

        if ($options['useCapture'] == true) {
            $options['useCapture'] = 'true';
        } else {
            $options['useCapture'] = 'false';
        }
        $isObject = (
            strpos($object, 'window') !== false || strpos($object, 'document') !== false ||
            strpos($object, '$(') !== false || strpos($object, '"') !== false ||
            strpos($object, '\'') !== false
        );

        if ($isObject) {
            $b = "Event.observe({$object}, '{$event}', function(event) { {$observer} }, ";
            $b .= "{$options['useCapture']});";
        } elseif ($object[0] == '\'') {
            $b = "Event.observe(" . substr($object, 1) . ", '{$event}', function(event) { ";
            $b .= "{$observer} }, {$options['useCapture']});";
        } else {
            $chars = ['#', ' ', ', ', '.', ':'];
            $found = false;
            foreach ($chars as $char) {
                if (strpos($object, $char) !== false) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $this->rules[$object] = $event;
            } else {
                $b = "Event.observe(\$('{$object}'), '{$event}', function(event) { ";
                $b .= "{$observer} }, {$options['useCapture']});";
            }
        }

        if (isset($b) && !empty($b)) {
            if ($this->cacheEvents === true) {
                $this->cachedEvents[] = $b;
                return '';
            } else {
                return $this->codeBlock($b, array_diff_key($options, $defaultOptions));
            }
        }
        return '';
    }

    /**
     * Cache JavaScript events created with event()
     *
     * @param boolean $file If true, code will be written to a file
     * @param boolean $all If true, all code written with JavascriptHelper will be sent to a file
     * @return null
     */
    public function cacheEvents($file = false, $all = false)
    {
        $this->cacheEvents = true;
        $this->cacheToFile = $file;
        $this->cacheAll = $all;
    }

    /**
     * Gets (and clears) the current JavaScript event cache
     *
     * @param boolean $clear
     * @return string
     */
    public function getCache($clear = true)
    {
        $rules = [];

        if (!empty($this->rules)) {
            foreach ($this->rules as $sel => $event) {
                $rules[] = "\t'{$sel}': function(element, event) {\n\t\t{$event}\n\t}";
            }
        }
        $data = implode("\n", $this->cachedEvents);

        if (!empty($rules)) {
            $data .= "\nvar Rules = {\n" . implode(",\n\n", $rules) . "\n}";
            $data .= "\nEventSelectors.start(Rules);\n";
        }
        if ($clear) {
            $this->rules = [];
            $this->cacheEvents = false;
            $this->cachedEvents = [];
        }
        return $data;
    }

    /**
     * Write cached JavaScript events
     *
     * @param boolean $inline If true, returns JavaScript event code.  Otherwise it is added to the
     *                        output of $scripts_for_layout in the layout.
     * @param array $options Set options for codeBlock
     * @return string
     */
    public function writeEvents($inline = true, $options = [])
    {
        if (!$this->cacheEvents) {
            return '';
        }
        $data = $this->getCache();

        if (empty($data)) {
            return '';
        }

        if ($this->cacheToFile) {
            $filename = md5($data);
            $out = $this->link($filename);
        } else {
            $out = $this->codeBlock("\n" . $data . "\n", $options);
        }

        if ($inline) {
            return $out;
        }
        return '';
    }

    /**
     * Includes the Prototype Javascript library (and anything else) inside a single script tag.
     *
     * Note: The recommended approach is to copy the contents of
     * javascripts into your application's
     * public/javascripts/ directory, and use @see javascriptIncludeTag() to
     * create remote script links.
     *
     * @param string $script Script file to include
     * @param array $options Set options for codeBlock
     * @return string script with all javascript in/javascripts folder
     */
    public function includeScript($script = "", $options = [])
    {
        if ($script == "") {
            $files = scandir(Configure::read('App.jsBaseUrl'));
            $javascript = '';

            foreach ($files as $file) {
                if (substr($file, -3) == '.js') {
                    $javascript .= file_get_contents(Configure::read('App.jsBaseUrl') . "{$file}") . "\n\n";
                }
            }
        } else {
            $javascript = file_get_contents(Configure::read('App.jsBaseUrl') . "$script.js") . "\n\n";
        }
        return $this->codeBlock("\n\n" . $javascript, $options);
    }

    /**
     * Generates a JavaScript object in JavaScript Object Notation (JSON)
     * from an array
     *
     * ### Options
     *
     * - block - Wraps the return value in a script tag if true. Default is false
     * - prefix - Prepends the string to the returned data. Default is ''
     * - postfix - Appends the string to the returned data. Default is ''
     * - stringKeys - A list of array keys to be treated as a string.
     * - quoteKeys - If false treats $stringKeys as a list of keys **not** to be quoted. Default is true.
     * - q - The type of quote to use. Default is '"'.  This option only affects the keys, not the values.
     *
     * @param array $data Data to be converted
     * @param array $options Set of options: block, prefix, postfix, stringKeys, quoteKeys, q
     * @return string A JSON code block
     */
    public function object($data = [], $options = [])
    {
        if (!empty($options) && !is_array($options)) {
            $options = ['block' => $options];
        } else if (empty($options)) {
            $options = [];
        }

        $defaultOptions = [
            'block' => false, 'prefix' => '', 'postfix' => '',
            'stringKeys' => [], 'quoteKeys' => true, 'q' => '"'
        ];
        $options = array_merge($defaultOptions, $options, array_filter(compact(array_keys($defaultOptions))));

        if (is_object($data)) {
            $data = get_object_vars((object)$data);
        }

        $out = $keys = [];
        $numeric = true;

        if ($this->useNative) {
            $rt = json_encode($data);
        } else {
            if (is_null($data)) {
                return 'null';
            }
            if (is_bool($data)) {
                return $data ? 'true' : 'false';
            }

            if (is_array($data)) {
                $keys = array_keys($data);
            }

            if (!empty($keys)) {
                $numeric = (array_values($keys) === array_keys(array_values($keys)));
            }

            foreach ($data as $key => $val) {
                if (is_array($val) || is_object($val)) {
                    $val = $this->object(
                        $val,
                        array_merge($options, ['block' => false, 'prefix' => '', 'postfix' => ''])
                    );
                } else {
                    $quoteStrings = (
                        !count($options['stringKeys']) ||
                        ($options['quoteKeys'] && in_array($key, $options['stringKeys'], true)) ||
                        (!$options['quoteKeys'] && !in_array($key, $options['stringKeys'], true))
                    );
                    $val = $this->value($val, $quoteStrings);
                }
                if (!$numeric) {
                    $val = $options['q'] . $this->value($key, false) . $options['q'] . ':' . $val;
                }
                $out[] = $val;
            }

            if (!$numeric) {
                $rt = '{' . implode(',', $out) . '}';
            } else {
                $rt = '[' . implode(',', $out) . ']';
            }
        }
        $rt = $options['prefix'] . $rt . $options['postfix'];

        if ($options['block']) {
            $rt = $this->codeBlock($rt, array_diff_key($options, $defaultOptions));
        }

        return $rt;
    }

    /**
     * Converts a PHP-native variable of any type to a JSON-equivalent representation
     *
     * @param mixed $val A PHP variable to be converted to JSON
     * @param boolean $quoteStrings If false, leaves string values unquoted
     * @return string a JavaScript-safe/JSON representation of $val
     */
    public function value($val, $quoteStrings = true)
    {
        switch (true) {
            case (is_array($val) || is_object($val)):
                $val = $this->object($val);
                break;
            case ($val === null):
                $val = 'null';
                break;
            case (is_bool($val)):
                $val = !empty($val) ? 'true' : 'false';
                break;
            case (is_int($val)):
                break;
            case (is_float($val)):
                $val = sprintf("%.11f", $val);
                break;
            default:
                $val = $this->escapeString($val);
                if ($quoteStrings) {
                    $val = '"' . $val . '"';
                }
                break;
        }
        return $val;
    }

    /**
     * Converts a multi byte character string
     * to the decimal value of the character
     *
     * @param multibyte string $string
     * @return array
     * @access public
     * @static
     */
    public static function utf8($string)
    {
        $map = [];

        $values = [];
        $find = 1;
        $length = strlen($string);

        for ($i = 0; $i < $length; $i++) {
            $value = ord($string[$i]);

            if ($value < 128) {
                $map[] = $value;
            } else {
                if (empty($values)) {
                    $find = ($value < 224) ? 2 : 3;
                }
                $values[] = $value;

                if (count($values) === $find) {
                    if ($find == 3) {
                        $map[] = (($values[0] % 16) * 4096) + (($values[1] % 64) * 64) + ($values[2] % 64);
                    } else {
                        $map[] = (($values[0] % 32) * 64) + ($values[1] % 64);
                    }
                    $values = [];
                    $find = 1;
                }
            }
        }
        return $map;
    }
}