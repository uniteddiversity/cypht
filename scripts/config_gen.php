<?php

/**
 * CLI script to build the site configuration
 */

/* debug mode has to be set to something or include files will die() */
define('DEBUG_MODE', false);

/* determine current absolute path used for require statements */
define('APP_PATH', dirname(dirname(__FILE__)).'/');

/* get the framework */
require APP_PATH.'lib/framework.php';

/* create site */
build_config();

/**
 * Entry point into the configuration process
 *
 * @return void
 */
function build_config() {
    /* get the site settings */
    $settings = parse_ini_file(APP_PATH.'hm3.ini');

    if (is_array($settings) && !empty($settings)) {

        /* determine compression commands */
        list($js_compress, $css_compress) = compress_methods($settings);

        /* get module detail */
        list($js, $css, $filters, $assets) = get_module_assignments($settings);

        /* combine and compress page content */
        combine_includes($js, $js_compress, $css, $css_compress);

        /* write out the hm3.rc file */
        write_config_file($settings, $filters);

        /* create the production version */
        create_production_site($assets, $settings);
    }
    else {
        printf("\nNo settings found in ini file\n");
    }
}

/**
 * Compress a string
 *
 * @param $string string content to compress
 * @param $command string command to do the compression
 *
 * @return string compressed string
 */
function compress($string, $command) {
    if ($command) {
        exec("echo ".escapeshellarg($string)." | $command", $output);
        return join('', $output);
    }
    else {
        return preg_replace("/\s+/", ' ', $string);
    }
}

/**
 * Check for site specific compression commands
 *
 * @param $settings array site settings list
 *
 * @return array compression methods or false for none
 */
function compress_methods($settings) {
    $js_compress = false;
    $css_compress = false;
    if (isset($settings['js_compress']) && $settings['js_compress']) {
        $js_compress = $settings['js_compress'];
    }
    if (isset($settings['css_compress']) && $settings['css_compress']) {
        $css_compress = $settings['css_compress'];
    }
    return array($js_compress, $css_compress);
}

/**
 * Get module content and filters. This function has a side effect of setting
 * up all the module assignments in Hm_Output_Modules and Hm_Handler_Modules.
 * (this happens when the module set's setup.php file is included).
 * These will be recorded later in the write_config_file function
 *
 * @param $settings array site settings list
 *
 * @return array js and css blobs, combined filers array, and module assets
 */
function get_module_assignments($settings) {
    $js = '';
    $css = '';
    $assets = array();
    $filters = array('allowed_output' => array(), 'allowed_get' => array(), 'allowed_cookie' => array(),
        'allowed_post' => array(), 'allowed_server' => array(), 'allowed_pages' => array());

    if (isset($settings['modules'])) {
        foreach (explode(',', $settings['modules']) as $mod) {
            printf("scanning module %s ...\n", $mod);
            if (is_readable(sprintf("modules/%s/site.js", $mod))) {
               $js .= file_get_contents(sprintf("modules/%s/site.js", $mod));
            }
            if (is_readable(sprintf("modules/%s/site.css", $mod))) {
               $css .= file_get_contents(sprintf("modules/%s/site.css", $mod));
            }
            if (is_readable(sprintf("modules/%s/setup.php", $mod))) {
                $filters = Hm_Router::merge_filters($filters, require sprintf("modules/%s/setup.php", $mod));
            }
            if (is_readable(sprintf("modules/%s/assets/", $mod))) {
                $assets[] = sprintf("modules/%s/assets/", $mod);
            }
        }
    }
    return array($js, $css, $filters, $assets);
}

/**
 * Write out combined javascript and css files
 *
 * @param $js string combined javascript from all modules
 * @param $js_compress string command to compress the js
 * @param $css string combined css from all modules
 * @param $css_compress string command to compress the css
 *
 * @return void
 */
function combine_includes($js, $js_compress, $css, $css_compress) {
    if ($css) {
        file_put_contents('site.css', compress($css, $css_compress));
        printf("site.css file created\n");
    }
    if ($js) {
        $js_lib = file_get_contents("third_party/zepto.min.js");
        $js = str_replace('\\', '\\\\', $js);
        file_put_contents('site.js', $js_lib.compress($js, $js_compress));
        printf("site.js file created\n");
    }
}

/**
 * Write the hm3.rc file to disk
 *
 * @param $settings array site settings list
 * @param $filters array combined list of filters from all modules
 * 
 * @return void
 */
function write_config_file($settings, $filters) {
    Hm_Handler_Modules::process_all_page_queue();
    Hm_Output_Modules::process_all_page_queue();
    $settings['handler_modules'] = Hm_Handler_Modules::dump();
    $settings['output_modules'] = Hm_Output_Modules::dump();
    $settings['input_filters'] = $filters;
    //build_config_map($settings);
    file_put_contents('hm3.rc', serialize($settings));
    printf("hm3.rc file written\n");
}

function build_config_map($settings) {
    $res = '<!DOCTYPE html><html dir="ltr" class="ltr_page" lang=en><head><title>Config Map</title>';
    $res .= '<style type="text/css">.page { padding: 10px; font-size: 120%; } .mod { padding-right: '.
        '10px; padding-left: 40px; }</style>';
    $res .= '</head><body><table>';
    $handlers = $settings['handler_modules'];
    $outputs = $settings['output_modules'];
    $filters = $settings['input_filters'];
    foreach ($handlers as $page => $mods) {
        $res .= '<tr><td colspan="2" class="page">'.$page.'</td></tr>';
        foreach ($mods as $name => $vals) {
            $res .= '<tr><td class="mod">handler</td><td>'.$name.'</td></tr>';
        }
        if (array_key_exists($page, $outputs)) {
            foreach($outputs[$page] as $name => $vals) {
                $res .= '<tr><td class="mod">output</td><td>'.$name.'</td></tr>';
            }
        }
    }
    $res .= '</table></body></html>';
    file_put_contents('config_map.html', $res);
}

/**
 * Copies the site.js and site.css files to the site/ directory, and creates
 * a production version of the index.php file.
 *
 * @return void
 */
function create_production_site($assets, $settings) {
    if (!is_readable('site/')) {
        mkdir('site', 0755);
    }
    printf("creating production site\n");
    copy('site.css', 'site/site.css');
    copy('site.js', 'site/site.js');
    $index_file = file_get_contents('index.php');
    $index_file = preg_replace("/APP_PATH', ''/", "APP_PATH', '".APP_PATH."'", $index_file);
    $index_file = preg_replace("/CACHE_ID', ''/", "CACHE_ID', '".urlencode(Hm_Crypt::unique_id(32))."'", $index_file);
    $index_file = preg_replace("/SITE_ID', ''/", "SITE_ID', '".urlencode(Hm_Crypt::unique_id(64))."'", $index_file);
    $index_file = preg_replace("/DEBUG_MODE', true/", "DEBUG_MODE', false", $index_file);
    file_put_contents('site/index.php', $index_file);
    foreach ($assets as $path) {
        if (!is_readable('site/'.$path)) {
            mkdir('site/'.$path, 0755, true);
        }
        foreach (scandir($path) as $file) {
            if (in_array($file, array('.', '..'), true)) {
                continue;
            }
            copy($path.$file, 'site/'.$path.$file);
        }
    }
}

?>
