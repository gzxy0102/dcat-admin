<?php

namespace Dcat\Admin\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

class JavaScript
{
    /**
     * json_encode 标志，防止内容破坏 script 上下文或注入 HTML.
     */
    const JSON_ENCODE_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    protected static $scripts = [];

    /**
     * @var string
     */
    protected $id;

    public function __construct($script)
    {
        $this->id = 'js('.Str::random().')';

        $this->value($script);
    }

    /**
     * 设置或获取代码内容.
     *
     * @param  mixed  $script
     * @return mixed
     */
    public function value($script = null)
    {
        if ($script === null) {
            return static::$scripts[$this->id];
        }

        static::$scripts[$this->id] = (string) value($script);
    }

    /**
     * @param  string|\Closure  $script
     * @return string
     */
    public static function make($script)
    {
        return (string) new static($script);
    }

    /**
     * 获取所有代码
     *
     * @return array
     */
    public static function all()
    {
        return static::$scripts;
    }

    /**
     * 删除代码.
     *
     * @param  string  $id
     */
    public static function delete(string $id)
    {
        unset(static::$scripts[$id]);
    }

    /**
     * 格式化为js代码.
     *
     * @param  array|Arrayable  $value
     * @return string
     */
    public static function format($value)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode(Helper::array($value, false), static::JSON_ENCODE_FLAGS);
        }

        foreach (static::all() as $id => $script) {
            $id = "\"$id\"";

            if (mb_strpos($value, $id) !== false) {
                $value = str_replace($id, $script, $value);

                static::delete($id);
            }
        }

        return $value;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->id;
    }
}
