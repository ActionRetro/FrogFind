<?php


class Locale {
    protected function parseLang($file): array
    {
        $string = file_get_contents($file);
        $string = preg_replace("%^\%{.*\%}\r?$%m", "", $string); #Remove comments
        $array  = [];
        
        foreach(preg_split("%;[\\r\\n]++%", $string) as $statement) {
            $s = explode(" = ", trim($statement));
            
            try {
                $array[eval("return $s[0];")] = eval("return $s[1];");
            } catch(\ParseError $ex) {
                echo "Could not parse locale :( At " . $s[0];
            }
        }

        return $array;
    }

    function trRaw($string, $locale) {
        $lang = empty($locale) ? "en-us" : $locale;
        $array = $this->parseLang(dirname(__FILE__) . "/languages/$lang.strings");

        return $array[$string] ?? "@$string";
    }
}
