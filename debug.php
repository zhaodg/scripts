<?php
/*

Behold ...  the 'UBER' LOGGER


This is useful in debugging the White Screen of Death, or cases where the there
 is no error log, or if the error log doesn't tell you where the error came from.

It tells php to really *really*, no just f$#%^ing really, log your error, srzly.

It also gives you a full stack trace and the URL which caused it (good for
isolating when it is actually an image or js request which is causing the error,
not the main page)

Just add this early in your page, it's already in local moodle config just uncomment it:

include_once('/var/www/common/dev-conf/debug.php');
include_once('/home/haywoodb/scripts/debug.php');

NOTE: If Moodle/drupal/whatever also creates it own handlers, add this script AFTER them
as this will chain to the existing handler.


*/

/**
 * Prints an array of arrays as a nice ascii table
 *
 */
function t($data) {

    $width = array();


    // Do a quick scan to calculate some column widths.
    $c = 0;
    foreach ($data as $row) {

        $c++;
        $row = (array)$row;

        foreach ($row as $key => $val) {
            if (empty($width[$key])) {
                $width[$key]= strlen($key);
            }
            $len = strlen($val);
            if ($len > $width[$key]) {
                $width[$key] = $len;
            }
        }

    }

    // Now print it.
    $c = 0;
    foreach ($data as $row) {

        if ($c++ == 0) {
            foreach ($row as $key => $val) {
                print "+" . str_repeat('-', $width[$key] + 2) ;
            }
            print "+\n";
            foreach ($row as $key => $val) {
                printf("| %{$width[$key]}s ", $key);
            }
            print "|\n";
            foreach ($row as $key => $val) {
                print "+" . str_repeat('-', $width[$key] + 2) ;
            }
            print "+\n";
        }

        foreach ($row as $key => $val) {
                printf("| %{$width[$key]}s ", $val);
        }
        print "|\n";
    }
}


/**
 * A convenience log function
 *
 * @$d an object or var to dump to error log with extra context info
 * @$t if true also print a nice stack trace
 *
 * If you commit a call to this you need to buy someone a beer, BAD!
 */
function e($d, $t = false){
    $trace = debug_backtrace();

    $stack = 'DEBUG: '.$trace[0]['file'] . ':'.$trace[0]['line'] .' => '. print_r($d,1);

    if ($t){
        unset($trace[0]); //Remove call to this function from stack trace
        $stack .= PHP_EOL;
        $i = 0;
        $len = 0;
        foreach($trace as $node) {
            $file = empty($node['file']) ? 'unkown' : $node['file'];
            $line = empty($node['line']) ? '???' : $node['line'];
            $part = "    #$i $file ($line): ";
            $len = max($len, strlen($part));
        }
        foreach($trace as $node) {
            $file = empty($node['file']) ? 'unkown' : $node['file'];
            $line = empty($node['line']) ? '???' : $node['line'];
            $stack .= sprintf("%-".$len."s", "    #$i $file:$line ");
            if(isset($node['class'])) {
                $stack .= $node['class'] . "->";
            }
            $stack .= $node['function'] . "()" . PHP_EOL;
            $i++;
        }
        $stack .= "\n URL: ".$_SERVER['REQUEST_URI'];
    }
    error_log($stack);
}

error_reporting(-1);

// ----------------------------------------------------------------------------------------------------
// - Shutdown Handler
// ----------------------------------------------------------------------------------------------------
function ShutdownHandler()
{
    if(@is_array($error = @error_get_last()))
    {
        return(@call_user_func_array('UberErrorHandler', $error));
    };

    return(TRUE);
};

register_shutdown_function('ShutdownHandler');

// ----------------------------------------------------------------------------------------------------
// - Error Handler
// ----------------------------------------------------------------------------------------------------

$old_error_handler = null;

function UberErrorHandler($type, $message='', $file='unknownfile', $line=0)
{
    if (defined('PHPUNIT_TEST')) { return; }

    $_ERRORS = Array(
        0x0001 => 'E_ERROR',
        0x0002 => 'E_WARNING',
        0x0004 => 'E_PARSE',
        0x0008 => 'E_NOTICE',
        0x0010 => 'E_CORE_ERROR',
        0x0020 => 'E_CORE_WARNING',
        0x0040 => 'E_COMPILE_ERROR',
        0x0080 => 'E_COMPILE_WARNING',
        0x0100 => 'E_USER_ERROR',
        0x0200 => 'E_USER_WARNING',
        0x0400 => 'E_USER_NOTICE',
        0x0800 => 'E_STRICT',
        0x1000 => 'E_RECOVERABLE_ERROR',
        0x2000 => 'E_DEPRECATED',
        0x4000 => 'E_USER_DEPRECATED'
    );

    if(!@is_string($name = @array_search($type, @array_flip($_ERRORS))))
    {
        $name = 'E_UNKNOWN:'.$type;
    };
    if ($name != 'E_NOTICE'
         && substr($message, 0, 6) != 'unlink'
         && substr($message, 0, 5) != 'chmod'
    ){
        error_log(@sprintf("UberErrorHandler: %s Error in %s:%d  %s\n URL: %s\n", $name, $file, $line, $message, $_SERVER['REQUEST_URI']));
    }
};

$old_error_handler = set_error_handler("UberErrorHandler");



// ----------------------------------------------------------------------------------------------------
// - Exception Handler
// ----------------------------------------------------------------------------------------------------

$old_exception_handler = null;

function UberExceptionHandler($ex) {

    global $old_exception_handler;
    if (!PHPUNIT_TEST) {
        error_log (sprintf('UberExceptionHandler: %s', $_SERVER['REQUEST_URI']));
    }

    // Now call the old error handler. Moodle registers it's own default handler which does
    // stuff like closing DB transactions, so we just want to augment that instead of replace it
    if ($old_exception_handler) {
        call_user_func( $old_exception_handler, $ex);
    }
}


$old_exception_handler = set_exception_handler("UberExceptionHandler");


function sql($sql){

    global $CFG;

    $sql = preg_replace("/\{(.*?)\}/", $CFG->prefix . "$1", $sql);

    print $sql."\n";

}

/**
 * Dumps the current http request as a curl command.
 *
 * If you call this on any page, eg at the start on config.php then it will
 * dump to the error log a cli curl command which will exactly reproduce
 * this same request. Great for watching and debugging a rest / xml-rpc call
 * or generally seeing what is coming in over the wire when you don't have
 * visibility at the network level.
 *
 * Inspired by the 'copy as cURL' in chrome dev tools.
 *
 * Gotchas: Depending on logs are processed, ie by syslog or apache, the
 * output may be encoded differently. For example a \ may be escaped into a
 * \\ which means if you are tailing the logs you may need to run it through
 * sed to get it back to a runnable command. This mostly applies to POST date.
 *
 */
function dump_as_curl($eol = false){

    $eol = $eol ? "\\n" : '';

    $cmd = "CURL command to reproduce:\n\ncurl $eol";

    $cmd .= " '";
    $cmd .= 'http';

    // TODO detect X-Forwarded https
    if ( (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ) {
        $cmd .= 's';
    }
    $cmd .= '://';
    $cmd .= $_SERVER['HTTP_HOST'];
    $cmd .= $_SERVER['REQUEST_URI'];
    $cmd .= "' ";
    $cmd .= $eol;

    $cmd .= " --insecure $eol";
    $cmd .= " --verbose $eol";
    // TODO --compressed ?

    $method = $_SERVER['REQUEST_METHOD'];
    switch ($method) {
        case 'HEAD':
            $cmd .= " --head $eol";
            break;
        case 'GET':
            $cmd .= '';
            break;
        default:
            $cmd .= " --request $method $eol";
    }

    $headers = getallheaders();
    foreach ($headers as $key => $val) {
        $cmd .= " --header '$key: $val' $eol";
    }

    $postdata = file_get_contents("php://input");

    if (!empty($postdata)) {
        $postdata = str_replace("'", "'" . "\\" . "'", $postdata); // Escape ' for use in shell
        $cmd .= " --data-binary '$postdata' $eol";
    }

    $cmd .= "\n";

    error_log($cmd);

// curl 'https://moodle.prod.local/lib/ajax/service.php?sesskey=RMV1nwHHip' -H 'Cookie: rocketchatscreenshare=chrome; MoodleSession=cgjtq6q42mkdckm6qm1p6bla12; MOODLEID1_=%2596K%2589%25A37mY%251F%25E1%25F8-' -H 'Origin: https://moodle.prod.local' -H 'Accept-Encoding: gzip, deflate, br' -H 'Accept-Language: en-US,en;q=0.8,de;q=0.6,ko;q=0.4' -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.84 Safari/537.36' -H 'Content-Type: application/json' -H 'Accept: application/json, text/javascript, */*; q=0.01' -H 'Referer: https://moodle.prod.local/admin/index.php?cache=1' -H 'X-Requested-With: XMLHttpRequest' -H 'Connection: keep-alive' --data-binary '[{"index":0,"methodname":"core_fetch_notifications","args":{"contextid":1}}]' --compressed --insecure

}

