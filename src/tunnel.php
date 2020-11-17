<?php //version lite202

//set allowTestMenu to false to disable System/Server test page
// 是否开启测试页面
define('AllowTestMenu', true);

define('FileDoesNotExist', 0);
define('FileCannotBeOpened', 1);
define('FileIsSQLite2', 2);
define('FileIsSQLite3', 3);
define('FileIsInvalid', 4);

/**
 * 入口函数
 * @param $event
 * @param $context
 * @return array
 */
function main_handler($event, $context)
{
    $headers = $body = null;
    extract(json_decode(json_encode($event), true));
//    print_r($event);
    if (strpos($headers['content-type'], 'multipart/form-data') !== false) {
        $post = parse_raw_http_request($headers['content-type'], $body);
    } else if (strpos($headers['content-type'], 'application/x-www-form-urlencoded') !== false) {
        parse_str($body, $post);
    }
    print_r($post);

    if (!isset($post["actn"]) || !isset($post["dbfile"])) {
        $testMenu = AllowTestMenu;
        if (!$testMenu) {
            return formatOutput(combine(EchoHeader(202), GetBlock("invalid parameters")));
        }
    }

    if (!$testMenu) {
        if ($action == "2" || $action == "3") {
            if (!isset($post["version"])) {
                return formatOutput(combine(EchoHeader(202), GetBlock("invalid parameters")));
            }
        }

        if (isset($post["encodeBase64"]) && $post["encodeBase64"] == '1') {
            for ($i = 0; $i < count($post["q"]); $i++)
                $post["q"][$i] = base64_decode($post["q"][$i]);
        }
        $action = $post["actn"];
        $file = $post["dbfile"];
        $queries = $post["q"];

        $status = FileDoesNotExist;
        if (is_file($file)) {
            $fhandle = fopen($file, "r");
            if ($fhandle) {
                $sqlite2header = "** This file contains an SQLite 2.1 database **";
                $sqlite3header = "SQLite format 3";
                $string = fread($fhandle, strlen($sqlite2header));
                if (strncmp($string, $sqlite2header, strlen($sqlite2header)) == 0)
                    $status = FileIsSQLite2;
                else if ($string == "" || strncmp(substr($string, 0, strlen($sqlite3header)), $sqlite3header, strlen($sqlite3header)) == 0)
                    $status = FileIsSQLite3;
                else
                    $status = FileIsInvalid;
                fclose($fhandle);
            } else {
                $status = FileCannotBeOpened;
            }
        } else {
            // 默认文件不存在时不会创建,这里新建一个
            if (!empty($file) && !file_exists($file)) {
                try {
                    $conn = new SQLite3($file, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                    $conn->close();
                    $status = FileIsSQLite3;
                } catch (Exception $e) {
                    $status = FileCannotBeOpened;
                }
            }
        }

        $echo = '';
        if ($action == "2" || $action == "3") {
            if ($status == FileDoesNotExist) {
                if ($action == "2")
                    $echo = SQLite2($action, $file, $queries);
                else
                    $echo = SQLite3($action, $file, $queries);

                return formatOutput($echo);
            } else {
                return formatOutput(combine(EchoHeader(202), GetBlock("Datbase file exists already")));
            }
        } else {
            switch ($status) {
                case FileDoesNotExist:
                    $echo = combine(EchoHeader(202), GetBlock("Database file does not exist"));
                    break;
                case FileCannotBeOpened:
                    $echo = combine(EchoHeader(202), GetBlock("Database file cannot be opened"));
                    break;
                case FileIsSQLite2:
                    $echo = SQLite2($action, $file, $queries);
                    break;
                case FileIsSQLite3:
                    $echo = SQLite3($action, $file, $queries);
                    break;
                case FileIsInvalid:
                    $echo = combine(EchoHeader(202), GetBlock("Database file is encrypted or invalid"));
                    break;
            }
            return formatOutput($echo);
        }
    }
    return doSystemTest();
}

function phpversion_int()
{
    list($maVer, $miVer, $edVer) = preg_split("(/|\.|-)", phpversion());
    return $maVer * 10000 + $miVer * 100 + $edVer;
}

function GetLongBinary($num)
{
    return pack("N", $num);
}

function GetShortBinary($num)
{
    return pack("n", $num);
}

function GetDummy($count)
{
    $str = "";
    for ($i = 0; $i < $count; $i++)
        $str .= "\x00";
    return $str;
}

function GetBlock($val)
{
    $len = strlen($val);
    if ($len < 254)
        return chr($len) . $val;
    else
        return "\xFE" . GetLongBinary($len) . $val;
}

function EchoHeader($errno)
{
    $str = GetLongBinary(1111);
    $str .= GetShortBinary(202);
    $str .= GetLongBinary($errno);
    $str .= GetDummy(6);
    return $str;
}

function EchoConnInfo()
{
    $version = sqlite_libversion();
    $str = GetBlock($version);
    $str .= GetBlock($version);
    $str .= GetBlock($version);
    return $str;
}

function EchoConnInfo3()
{
    $version = SQLite3::version();
    $str = GetBlock($version["versionString"]);
    $str .= GetBlock($version["versionString"]);
    $str .= GetBlock($version["versionString"]);
    return $str;
}

function EchoResultSetHeader($errno, $affectrows, $insertid, $numfields, $numrows)
{
    $str = GetLongBinary($errno);
    $str .= GetLongBinary($affectrows);
    $str .= GetLongBinary($insertid);
    $str .= GetLongBinary($numfields);
    $str .= GetLongBinary($numrows);
    $str .= GetDummy(12);
    return $str;
}

function EchoFieldsHeader($res, $numfields)
{
    $str = "";
    for ($i = 0; $i < $numfields; $i++) {
        $str .= GetBlock(sqlite_field_name($res, $i));
        $str .= GetBlock("");

        $type = -2;    // SQLITE_TEXT
        $length = 0;
        $flag = 0;

        $str .= GetLongBinary($type);
        $str .= GetLongBinary($flag);
        $str .= GetLongBinary($length);
    }
    return $str;
}

function EchoFieldsHeader3($res, $numfields)
{
    $str = "";
    for ($i = 0; $i < $numfields; $i++) {
        $str .= GetBlock($res->columnName($i));
        $str .= GetBlock("");

        $type = SQLITE3_NULL;
        $length = 0;
        $flag = 0;

        $str .= GetLongBinary($type);
        $str .= GetLongBinary($flag);
        $str .= GetLongBinary($length);
    }
    return $str;
}

function EchoData($res, $numfields, $numrows)
{
    $str = "";
    for ($i = 0; $i < $numrows; $i++) {
        $row = sqlite_fetch_array($res, SQLITE_NUM);
        for ($j = 0; $j < $numfields; $j++) {
            if (is_null($row[$j]))
                $str .= "\xFF";
            else
                $str .= GetBlock($row[$j]);
            $str .= GetLongBinary(-2);
        }
    }
    return $str;
}

function EchoData3($res, $numfields, $numrows)
{
    $str = "";
    while ($row = $res->fetchArray(SQLITE3_NUM)) {
        for ($j = 0; $j < $numfields; $j++) {
            if (is_null($row[$j]))
                $str .= "\xFF";
            else
                $str .= GetBlock($row[$j]);
            $str .= GetLongBinary($res->columnType($j));
        }

    }
    return $str;
}

function SQLite2($action, $path, $queries)
{
    if (!function_exists("sqlite_open")) {
        return combine(EchoHeader(203), GetBlock("SQLite2 is not supported on the server"));
    }


    $errno_c = 0;
    $conn = sqlite_open($path, 0666, $error_c);
    if ($conn == FALSE) {
        $errno_c = 202;
    }
    // 新增echo变量
    $echo = EchoHeader($errno_c);
    if ($errno_c > 0) {
        $echo .= GetBlock(sqlite_error_string($error_c));
    } elseif ($action == "C") {
        $echo .= EchoConnInfo();
    } elseif ($action == "2") {
        sqlite_query($conn, "VACUUM");
        $echo .= EchoConnInfo();
    } elseif ($action == "Q") {
        for ($i = 0; $i < count($queries); $i++) {
            $query = $queries[$i];
            if ($query == "") continue;
            if (phpversion_int() < 50400) {
                if (get_magic_quotes_gpc())
                    $query = stripslashes($query);
            }
            $res = sqlite_query($conn, $query);
            $errno = sqlite_last_error($conn);
            $affectedrows = sqlite_changes($conn);
            $insertid = sqlite_last_insert_rowid($conn);
            $numfields = sqlite_num_fields($res);
            $numrows = sqlite_num_rows($res);
            $echo .= EchoResultSetHeader($errno, $affectedrows, $insertid, $numfields, $numrows);
            if ($errno != 0)
                $echo .= GetBlock(sqlite_error_string($errno));
            else {
                if ($numfields > 0) {
                    $echo .= EchoFieldsHeader($res, $numfields);
                    $echo .= EchoData($res, $numfields, $numrows);
                } else {
                    $echo .= GetBlock("");
                }
            }
            if ($i < (count($queries) - 1))
                $echo .= "\x01";
            else
                $echo .= "\x00";
        }
    }

    sqlite_close($conn);

    return $echo;
}

function SQLite3($action, $path, $queries)
{
    if (!class_exists("Sqlite3")) {
        return combine(EchoHeader(203), GetBlock("SQLite3 is not supported on the server"));
    }

    $flag = SQLITE3_OPEN_READWRITE;
    if ($action == "3")
        $flag = SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE;

    $errno_c = 0;
    try {
        $conn = new SQLite3($path, $flag);
    } catch (Exception $e) {
        $errno_c = 202;
    }
    $echo = EchoHeader($errno_c);
    if ($errno_c > 0) {
        $echo .= GetBlock((new SQLite3)->lastErrorMsg());
    } else if ($action == "C") {
        $echo .= EchoConnInfo3();
    } else if ($action == "3") {
        $conn->query("VACUUM");
        $echo .= EchoConnInfo3();
    } else if ($action == "Q") {
        for ($i = 0; $i < count($queries); $i++) {
            $query = $queries[$i];
            if ($query == "") continue;
            if (phpversion_int() < 50400) {
                if (get_magic_quotes_gpc())
                    $query = stripslashes($query);
            }
            $res = $conn->query($query);
            $errno = $conn->lastErrorCode();
            $affectedrows = $conn->changes();
            $insertid = $conn->lastInsertRowID();
            $numfields = 0;
            $numrows = 0;
            if (is_a($res, "SQLite3Result")) {
                $numfields = $res->numColumns();
                if ($numfields > 0) {
                    while ($row = $res->fetchArray(SQLITE3_NUM)) {
                        $numrows++;
                    }
                    $res->reset();
                }
            }
            $echo .= EchoResultSetHeader($errno, $affectedrows, $insertid, $numfields, $numrows);
            if ($errno != 0)
                $echo .= GetBlock($conn->lastErrorMsg());
            else {
                if ($numfields > 0) {
                    $echo .= EchoFieldsHeader3($res, $numfields);
                    $echo .= EchoData3($res, $numfields, $numrows);
                    $res->finalize();
                } else {
                    $echo .= GetBlock("");
                }
            }
            if ($i < (count($queries) - 1))
                $echo .= "\x01";
            else
                $echo .= "\x00";
        }
    }

    $conn->close();

    return $echo;
}

/**
 * 新增字符串连接
 * @param mixed ...$str
 * @return string
 */
function combine(...$str)
{
    return implode('', $str);
}

function formatOutput($content)
{
    return [
        'isBase64Encoded' => true,
        'statusCode' => 200,
        'headers' => array('Content-Type' => 'text/plain'),
        'body' => base64_encode($content)
    ];
}

function output($description, $succ, $resStr)
{
    return "<tr><td class=\"TestDesc\">$description</td><td " .
        (($succ) ? "class=\"TestSucc\">$resStr[0]</td></tr>" : "class=\"TestFail\">$resStr[1]</td></tr>");
}

/**
 * 不同于传统的PHP,$_POST,php://input都没有数据,经过API网关过来的post数据不会自动解析,修改别人写好的函数去解析post过来的数据
 * 参考: https://gist.github.com/cwhsu1984/3419584ad31ce12d2ad5fed6155702e2
 * @param $contentType
 * @param $body
 * @return array
 */
function parse_raw_http_request($contentType, $body)
{
    // grab multipart boundary from content type header
    preg_match('/boundary=(.*)$/', $contentType, $matches);

    // content type is probably regular form-encoded
    if (!count($matches)) {
        // we expect regular puts to containt a query string containing data
        parse_str(urldecode($body), $data);
        return $data;
    }

    $boundary = $matches[1];

    // split content by boundary and get rid of last -- element
    $a_blocks = preg_split("/-+$boundary/", $body);
    array_pop($a_blocks);

    $keyValueStr = '';
    // loop data blocks
    foreach ($a_blocks as $id => $block) {
        if (empty($block))
            continue;

        // you'll have to var_dump $block to understand this and maybe replace \n or \r with a visibile char

        // parse uploaded files
        if (strpos($block, 'application/octet-stream') !== FALSE) {
            // match "name", then everything after "stream" (optional) except for prepending newlines
            preg_match("/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s", $block, $matches);
            $a_data['files'][$matches[1]] = $matches[2];
        } // parse all other fields
        else {
            // match "name" and optional value in between newline sequences
            preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $block, $matches);
            $keyValueStr .= $matches[1] . "=" . $matches[2] . "&";
        }
    }
    $keyValueArr = [];
    parse_str($keyValueStr, $keyValueArr);
    return $keyValueArr;
}

function doSystemTest()
{
    $echo = output("[SQLite2] PHP version >= 5.0.0", phpversion_int() >= 50000, array("Yes", "No"));
    $echo .= output("[SQLite2] sqlite_open() available", function_exists("sqlite_open"), array("Yes", "No"));
    $echo .= output("[SQLite3] PHP version >= 5.3.0", phpversion_int() >= 50300, array("Yes", "No"));
    $echo .= output("[SQLite3] SQLite3 class available", class_exists("SQLite3"), array("Yes", "No"));
    if (phpversion_int() >= 40302 && substr($_SERVER["SERVER_SOFTWARE"], 0, 6) == "Apache" && function_exists("apache_get_modules")) {
        if (in_array("mod_security2", apache_get_modules()))
            $echo .= output("Mod Security 2 installed", false, array("No", "Yes"));
    }

    return [
        'isBase64Encoded' => false,
        'statusCode' => 200,
        'headers' => array('Content-Type' => 'text/html'),
        'body' => str_replace('{{TEST_RESULT}}', $echo, file_get_contents('test.html'))
    ];
}
