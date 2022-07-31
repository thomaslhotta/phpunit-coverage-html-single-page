<?php
namespace PHPUnitCompileCodeCoverage;

use cli,
	RecursiveDirectoryIterator,
	RecursiveIteratorIterator,
	SplFileInfo,
	ZipArchive;

function compile() {
    // Command line argument handling
    $arguments = new cli\Arguments(compact('strict'));

    $arguments->addFlag(
        array('help', 'h'),
        'Show this help screen'
    );
    $arguments->addFlag(
        array('overwrite', 'x'),
        'Overwrite original index and delete source files '
    );

    $arguments->addOption(
        array('output', 'o'),
        array(
            'default'     => false,
            'description' => 'Output HTML file name'
        )
    );
    $arguments->addOption(
        array('source', 's'),
        array(
            'default'     => false,
            'description' => 'Source directory'
        )
    );

    $arguments->parse();
    if ($arguments['help']) {
        echo $arguments->getHelpScreen();
        cli\line();
        cli\line();
        die();
    }

    if ( empty( $arguments['source'] ) ) {
        cli\line( 'Not source defined' );
        die();
    }

    $source_dir = rtrim( realpath( $arguments['source'] ), '/' );

    if ( $arguments['overwrite'] ) {
        $arguments['output'] = $source_dir . '/index.html';
    }

    // Create our new single HTML file
    $new_index_content = file_get_contents($source_dir . '/index.html');
    $new_index_content = str_replace( get_body_content( $new_index_content ), '{body}', $new_index_content  );

    // Find all HTML files and put them in an array
    $directory = new RecursiveDirectoryIterator($source_dir);
    $iterator  = new RecursiveIteratorIterator($directory);
    $files     = [];
    foreach ($iterator as $info) {
        /* @var SplFileInfo $info */
        if ('html' !== $info->getExtension()) {
            continue;
        }

        if (in_array($info->getFilename(), ['xdashboard.html', 'new.html'])) {
            continue;
        }

        $key         = str_replace($source_dir . '/', '', $info->getPathname());
        $content     = file_get_contents($info->getPathname());
        $files[$key] = $content;

        if ( $arguments['overwrite'] ) {
            unlink( $info->getPathname() );
        }
    }

    $progress = new cli\progress\Bar( 'Processing files', count(  $files) );

    // Build zip file from all found HTML files
    $zip = new ZipArchive();
    $zip->open( __DIR__ . '/temp.zip', ZipArchive::CREATE|ZipArchive::OVERWRITE );
    foreach ($files as $path => $content) {
        $progress->tick();
        // Fix links
        $content = replace_link_attributes($content, 'a', $path, $source_dir, '?path=');
        $content = replace_link_attributes($content, 'script', $path, $source_dir);
        $content = replace_link_attributes($content, 'link', $path, $source_dir);
        // Create new container
        $zip->addFromString( $path, $content );
    }

    $zip->close();
    $progress->finish();

    // Inject JS necessary for handling display and the zipped HTML files
    $compiled = '<script type="text/javascript">
        '. file_get_contents(__DIR__ . '/jszip.min.js') . '
        
        var contentZip ="' . base64_encode(file_get_contents(__DIR__ . '/temp.zip')) . '";
        
        (function(){
            var urlParams = new URLSearchParams(window.location.search),
                path = urlParams.get("path") ? urlParams.get("path") : "",
                zip = new JSZip();
            
            if ( -1 === path.indexOf(".html")  ) {
                path += "index.html";
            }
            
            zip.loadAsync(contentZip,{
                base64: true,
            }).then(function (zip) {
                if ( ! zip.file( path ) ) {
                    alert("Could not find file in zipped content: " + path );
                    return;
                }
                
                zip.file( path ).async( "string" ).then( function(content){
                    var contentDom = document.createElement("html"),
                        scripts = [];
                    
                    contentDom.innerHTML = content;
                    
                    // Import stylesheets
                    contentDom.querySelectorAll( "head link[rel=stylesheet]" ).forEach( function(stylesheet) {
                        window.document.head.append( document.importNode(stylesheet) );
                    });
                    
                    // Import scripts
                    contentDom.querySelectorAll( "script" ).forEach( function(oldScript) {
                        var script = document.createElement( "script" );
                        script.async = false;
                        script.defer = false;
                        if ( oldScript.src ) {
                            script.src = oldScript.src;
                        } else {
                            // Turn inline scripts into data URIs to preserve loadging order
                            script.src = "data:text/javascript;base64," + btoa( oldScript.innerHTML);
                        }
                        
                        scripts.push( script );
                        oldScript.remove();
                    });
                    
                    // Replace body
                    document.body.replaceWith( document.importNode( contentDom.querySelector( "body" ), true ) );
                    
                    // Add imported scripts
                    scripts.forEach( function( script ) {
                        document.body.append( script );
                    } );

                    // Scroll to requested anchor
                    if (window.location.hash != "") {
                        element_to_scroll_to = document.getElementById(window.location.hash.slice(1));
                        element_to_scroll_to.scrollIntoView();
                    }
                    
                });
            }, function() {
                alert("Cloud not open zipped content.");
            });
        })();
       </script>';

    $new_index_content = str_replace( '{body}', $compiled, $new_index_content );
    unlink( __DIR__ . '/temp.zip' );
    file_put_contents( $arguments['output'], $new_index_content );
}

/* Helper functions */

/**
 * Searches for tags containing link attributes like src or href and replaces converts them to the correct format
 *
 * @param $content
 * @param $tag
 * @param $current_path
 * @param $base_path
 * @param string $prefix
 *
 * @return string|string[]|null
 */
function replace_link_attributes(string $content, string $tag, string $current_path, string $base_path, string $prefix = '')
{
    $attributes = [
        'script' => 'src',
        'link'   => 'href',
        'a'      => 'href',
    ];

    $content = preg_replace_callback(
        '/(<' . $tag . '\s[^>]*' . $attributes[$tag] . '=\")([^\"#]*)([^\"]*)(\"[^>]*>)/',
        function ($matches) use ($base_path, $current_path, $prefix) {
            if ($matches[2]==='' && 0 === strpos($matches[3], '#')) {
                return $matches[1] . $matches[2] . $matches[3] . $matches[4];
            }

            $matches[2] = realpath($base_path . '/' . dirname($current_path) . '/' . $matches[2]);
            $matches[2] = str_replace($base_path . '/', '', $matches[2]);

            return $matches[1] . $prefix . $matches[2] . $matches[3] . $matches[4];
        },
        $content
    );

    return $content;
}

/**
 * Returns the content of the body of the given HTML
 *
 * @param $html
 *
 * @return string
 */
function get_body_content( $html ) {
    $matches = [];
    preg_match( '/<body[^>]*>(.*)<\/body>/s', $html, $matches  );
    $html = $matches[1];
    return trim($html);
}