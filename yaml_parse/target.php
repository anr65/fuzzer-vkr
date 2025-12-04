<?php

use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// Подключаем автозагрузчик
require __DIR__ . '/../vendor/autoload.php';

/** @var \PhpFuzzer\Config $config*/


// Настройка цели фаззинга
$config->setTarget(function (string $input) {
    try {
        $parser = new Parser();

        //
        // 1) parseFile() на не-существующий и не-читаемый файл
        //
        try {
            $parser->parseFile(__DIR__ . '/does-not-exist.yml');
        } catch (ParseException $e) {
            // should hit "File ... does not exist."
        }

        $tmp = tempnam(sys_get_temp_dir(), 'yf');
        file_put_contents($tmp, $input);
        chmod($tmp, 0000);
        try {
            $parser->parseFile($tmp);
        } catch (ParseException $e) {
            // should hit "cannot be read"
        }
        @unlink($tmp);

        //
        // 2) быстрая проверка пустых входов и комментариев
        //
        foreach (["", "\n", "# just a comment\n# another\n"] as $doc) {
            try {
                $parser->parse($doc, 0);
            } catch (ParseException $e) {
                // skip
            }
        }

        //
        // 3) тест табов в начале строки
        //
        try {
            $parser->parse("\tbad: indent");
        } catch (ParseException $e) {
            // "A YAML file cannot contain tabs as indentation."
        }

        //
        // 4) основной набор флагов
        //
        $flagsList = [
            0,
            Yaml::PARSE_CUSTOM_TAGS,
            Yaml::PARSE_OBJECT_FOR_MAP,
            Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_OBJECT_FOR_MAP,
        ];

        //
        // 5) расширенный корпус «специальных» документов
        //
        $docs = [
            "key: {$input}",
            // тег без отступа и с отступом
            "!mytag\n  value: {$input}",
            // встроенный тег binary
            "!!binary |+\n  " . rtrim(base64_encode($input)) . "\n",
            // неправильный блок
            "- ? bad",
            // merge-узел с массивом
            "&r {$input}\nr: *r",
            "<<: [ {a:1}, {b:2} ]",
            // merge-узел с одиночным mapping
            "<<: &m {x:1}\nmap: *m",
            // блок-скаляр folded/chomp
            ">-\n  line1: {$input}\n  line2\n",
            // literal/chomp
            "|\n  hello\n world\n",
            "|-\n  one\n  two\n",
            // colon in unquoted mapping value
            "foo: unquoted:colon",
            // bare sequence then mapping (ошибка)
            "- item1\nkey: item2",
            // float numeric ключ
            "3.14: pi",
            // inline-mapping и inline-sequence
            "{foo: {$input}, bar: [1,2,3]}",
            "[alpha, {$input}, gamma]",
            // YAML header и document markers
            "%YAML 1.2\n---\nfoo: bar\n...\n",
        ];

        foreach ($docs as $doc) {
            foreach ($flagsList as $flags) {
                try {
                    $parser->parse($doc, $flags);
                } catch (ParseException $e) {
                    // ignore
                }
            }
        }

        //
        // 6) ещё раз run parse() произвольного input, чтобы ловить UTF-8 ошибки
        //
        try {
            $parser->parse($input, 0);
        } catch (ParseException $e) {
            // если в input невалидный UTF-8
        }
    } catch (ParseException $e) {
        // Синтаксические/семантические ошибки YAML игнорируем
        return;
    }
});
