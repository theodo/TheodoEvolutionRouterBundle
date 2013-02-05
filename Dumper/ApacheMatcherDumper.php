<?php

namespace Theodo\Evolution\RouterBundle\Dumper;

use Symfony\Component\Routing\Matcher\Dumper\MatcherDumper;

/**
 * Dumps a set of Apache mod_rewrite rules.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Kris Wallsmith <kris@symfony.com>
 * @author Mathieu Dähne <mathieud@theodo.fr>
 * @author Pierre-Henri Cumenge <pierrehenric@theodo.fr>
 */
class ApacheMatcherDumper extends MatcherDumper
{

    /**
     * Dumps the rewrites rules prefixed and suffixed if necessary
     * @return string
     */
    public function dump(array $options = array())
    {
        $prefix = $this->getPrefix();
        $rules = $this->getRouteRewrites($options = array());
        $suffix = $this->getSuffix();
        if ($prefix !== '') {
            array_unshift($rules, $prefix);
        }
        if ($suffix !== '') {
            $rules[] = $suffix;
        }

        return implode("\n\n", $rules) . "\n";
    }

    /**
     * Dumps a set of Apache mod_rewrite rules.
     * (Symfony router component slightly adapted)
     *
     * Available options:
     *
     *  * script_name: The script name (app.php by default)
     *  * base_uri:    The base URI ("" by default)
     *
     * @param array $options An array of options
     *
     * @return array an array containing the route rewrites as strings
     *
     * @throws \LogicException When the route regex is invalid
     */
    public function getRouteRewrites(array $options = array())
    {
        $options = array_merge(array(
            'script_name' => 'app.php',
            'base_uri'    => '',
        ), $options);

        $options['script_name'] = self::escape($options['script_name'], ' ', '\\');



        $rules[] = "#### Symfony2 routing part (Start) ####\n# skip \"real\" requests\n#RewriteCond %{REQUEST_FILENAME} -f\n#RewriteRule .* - [QSA,L]";
        $methodVars = array();

        foreach ($this->getRoutes()->all() as $name => $route) {
            if (0 === strpos($name, 'legacy_')) {
                continue;
            }

            $compiledRoute = $route->compile();

            // prepare the apache regex
            $regex = $compiledRoute->getRegex();
            $delimiter = $regex[0];
            $regexPatternEnd = strrpos($regex, $delimiter);
            if (strlen($regex) < 2 || 0 === $regexPatternEnd) {
                throw new \LogicException('The "%s" route regex "%s" is invalid', $name, $regex);
            }
            $regex = preg_replace('/\?P<.+?>/', '', substr($regex, 1, $regexPatternEnd - 1));
            $regex = '^'.self::escape(preg_quote($options['base_uri']).substr($regex, 1), ' ', '\\');

            $methods = array();
            if ($req = $route->getRequirement('_method')) {
                $methods = explode('|', strtoupper($req));
                // GET and HEAD are equivalent
                if (in_array('GET', $methods) && !in_array('HEAD', $methods)) {
                    $methods[] = 'HEAD';
                }
            }

            $hasTrailingSlash = (!$methods || in_array('HEAD', $methods)) && '/$' === substr($regex, -2) && '^/$' !== $regex;

            $variables = array('E=_ROUTING__route:'.$name);
            foreach ($compiledRoute->getVariables() as $i => $variable) {
                $variables[] = 'E=_ROUTING_'.$variable.':%'.($i + 1);
            }
            foreach ($route->getDefaults() as $key => $value) {
                $variables[] = 'E=_ROUTING_DEFAULTS_'.$key.':'.strtr($value, array(
                    ':'  => '\\:',
                    '='  => '\\=',
                    '\\' => '\\\\',
                    ' '  => '\\ ',
                ));
            }
            $variables = implode(',', $variables);

            $rule = array("# $name");

            // method mismatch
            if ($req = $route->getRequirement('_method')) {
                $methods = explode('|', strtoupper($req));
                // GET and HEAD are equivalent
                if (in_array('GET', $methods) && !in_array('HEAD', $methods)) {
                    $methods[] = 'HEAD';
                }
                $allow = array();
                foreach ($methods as $method) {
                    $methodVars[] = $method;
                    $allow[] = 'E=_ROUTING__allow_'.$method.':1';
                }

                $rule[] = "RewriteCond %{REQUEST_URI} $regex";
                $rule[] = sprintf("RewriteCond %%{REQUEST_METHOD} !^(%s)$ [NC]", implode('|', $methods));
                $rule[] = sprintf('RewriteRule .* - [S=%d,%s]', $hasTrailingSlash ? 2 : 1, implode(',', $allow));
            }

            // redirect with trailing slash appended
            if ($hasTrailingSlash) {
                $rule[] = 'RewriteCond %{REQUEST_URI} '.substr($regex, 0, -2).'$';
                $rule[] = 'RewriteRule .* $0/ [QSA,L,R=301]';
            }

            // the main rule
            $rule[] = "RewriteCond %{REQUEST_URI} $regex";
            $rule[] = "RewriteRule .* {$options['script_name']} [QSA,L,$variables]";

            $rules[] = implode("\n", $rule);
        }

        /*if (0 < count($methodVars)) {
            $rule = array('# 405 Method Not Allowed');
            $methodVars = array_values(array_unique($methodVars));
            foreach ($methodVars as $i => $methodVar) {
                $rule[] = sprintf('RewriteCond %%{_ROUTING__allow_%s} !-z%s', $methodVar, isset($methodVars[$i + 1]) ? ' [OR]' : '');
            }
            $rule[] = sprintf('RewriteRule .* %s [QSA,L]', $options['script_name']);

            $rules[] = implode("\n", $rule);
        }*/


        return $rules;
    }

    public function getPrefix()
    {
        return <<<EOF
Options +FollowSymLinks +ExecCGI

<IfModule mod_rewrite.c>
  RewriteEngine On

  # uncomment the following line, if you are having trouble
  # getting no_script_name to work
  #RewriteBase /

  # we skip all files with .something
  #RewriteCond %{REQUEST_URI} \..+$
  #RewriteCond %{REQUEST_URI} !\.html$
  #RewriteRule .* - [L]

  RewriteCond %{REQUEST_URI} \.(html|css|js)$
  RewriteCond %{REQUEST_URI} ^/(bundles|css|js|images)/
  RewriteRule .* - [L]
EOF;
    }

    public function getSuffix()
    {
        return <<<EOF
#### Symfony2 routing part (End) ####

  # we check if the .html version is here (caching)
  RewriteRule ^$ index.html [QSA]
  RewriteRule ^([^.]+)$ $1.html [QSA]
  RewriteCond %{REQUEST_FILENAME} !-f

  # no, so we redirect to our front web controller
  RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>
EOF;
    }

    /**
     * Escapes a string.
     *
     * @param string $string The string to be escaped
     * @param string $char   The character to be escaped
     * @param string $with   The character to be used for escaping
     *
     * @return string The escaped string
     */
    private static function escape($string, $char, $with)
    {
        $escaped = false;
        $output = '';
        foreach (str_split($string) as $symbol) {
            if ($escaped) {
                $output .= $symbol;
                $escaped = false;
                continue;
            }
            if ($symbol === $char) {
                $output .= $with.$char;
                continue;
            }
            if ($symbol === $with) {
                $escaped = true;
            }
            $output .= $symbol;
        }

        return $output;
    }
}
