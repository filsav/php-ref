<?php



/**
 * Shortcut to ref::transform(), HTML mode
 *
 * @version  1.0
 * @param    mixed $args
 */
function r(){

  // arguments passed to this function
  $args = func_get_args();

  // options (operators) gathered by the expression parser;
  // this variable gets passed as reference to getInputExpressions(), which will store the operators in it
  $options = array();

  // doh
  $output = array();

  // names of the arguments that were passed to this function
  $expressions = ref::getInputExpressions($options);

  // something went wrong while trying to parse the source expressions?
  // if so, silently ignore this part and leave out the expression info
  if(func_num_args() !== count($expressions))
    $expressions = array();

  // get the info
  foreach($args as $index => $arg)
    $output[] = ref::transform($arg, isset($expressions[$index]) ? $expressions[$index] : null, 'html');

  $output = implode("\n\n", $output);

  // return the results if this function was called with the error suppression operator 
  if(in_array('@', $options, true))
    return $output;

  // IE goes funky if there's no doctype
  if(!headers_sent() && !ob_get_length())
    print '<!DOCTYPE HTML><html><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /><body>';

  print $output;

  // stop the script if this function was called with the bitwise not operator
  if(in_array('~', $options, true))
    exit(0);
}  



/**
 * Shortcut to ref::transform(), plain text mode
 *
 * @version  1.0
 * @param    mixed $args
 */
function rt(){
  $args        = func_get_args();
  $options     = array();  
  $output      = array();  
  $expressions = ref::getInputExpressions($options);

  if(func_num_args() !== count($expressions))
    $expressions = array();

  foreach($args as $index => $arg)
    $output[] = ref::transform($arg, isset($expressions[$index]) ? $expressions[$index] : null, 'text');

  $output = implode('', $output);

  if(in_array('@', $options, true))
    return $output;  

  if(!headers_sent())    
    header('Content-Type: text/plain; charset=utf-8');

  print $output;

  if(in_array('~', $options, true))
    exit(0);  
}



/**
 * REF is a nicer alternative to PHP's print_r() / var_dump().
 *
 * @version  1.0
 * @author   digitalnature, http://digitalnature.eu
 */
class ref{

  protected static

    // initial expand depth (for HTML mode only)
    $expandDepth  = 1,  

    // shortcut functions used to access the ::build() method below;
    // if they are namespaced, the namespace must be present as well (methods are not supported)
    $shortcutFunc = array('r', 'rt'),
    
    // default output format
    $outputFormat = 'html',

    // stylesheet path (for HTML only);
    // 'false' means no styles
    $stylePath    = '{:dir}/ref.css',

    // javascript path (for HTML only);
    // 'false' means no js
    $scriptPath   = '{:dir}/ref.js',

    // cpu time used
    $time         = 0;



  protected

    // temporary element (marker) for arrays, used to track recursions
    $arrayMarker  = null,

    // tracks objects to detect recursion
    $objectHashes = array(),

    // current nesting level
    $level        = 0,

    // output format
    $format       = 'html';



  /**
   * Currently the constructor will only set up the output format.
   *
   * Other options might be added in the future
   *
   * @since   1.0
   * @param   string $format
   */
  public function __construct($format = 'html'){
    $this->format = $format;
  }



  /**
   * Set or get configuration options
   *
   * @since   1.0
   * @param   string $key
   * @param   mixed|null $value
   * @return  mixed
   */
  public static function config($key, $value = null){

    $validKeys = array(
      'shortcutFunc',
      'expandDepth',
      'outputFormat',
      'stylePath',
      'scriptPath',
    );

    if(!in_array($key, $validKeys, true))
      throw new Exception(sprintf('Unrecognized option: "%s". Valid options are: %s', $key, implode(', ', $validKeys)));

    if($value === null)
      return static::${$key};

    if(is_array(static::${$key}))
      return static::${$key} = (array)$value;

    return static::${$key} = $value;
  }



  /**
   * Get all parent classes of a class
   *
   * @since   1.0
   * @param   string|object $class   Class name or object
   * @param   bool $internalOnly     Retrieve only PHP-internal classes
   * @return  array
   */
  protected function getParentClasses($class, $internalOnly = false){

    $haveParent = ($class instanceof \Reflector) ? $class : new \ReflectionClass($class);
    $parents = array();

    while($haveParent !== false){
      if(!$internalOnly || ($internalOnly && $haveParent->isInternal()))
        $parents[] = $haveParent;

      $haveParent = $haveParent->getParentClass();
    }

    return $parents;
  }



  /**
   * Generate class info
   *
   * @since   1.0
   * @param   string|object $class   Class name or object
   * @return  string
   */
  protected function transformClassString($class){

    if(!($class instanceof \Reflector))
      $class = new \ReflectionClass($class);

    $classes = $this->getParentClasses($class) + array($class);

    foreach($classes as &$class){

      $modifiers = array();

      if($class->isAbstract())
        $modifiers[] = array('abstract', 'A', 'This class is abstract');

      if($class->isFinal())
        $modifiers[] = array('final', 'F', 'This class is final and cannot be extended');

      // php 5.4+ only
      if((PHP_MINOR_VERSION > 3) && $class->isCloneable())
        $modifiers[] = array('cloneable', 'C', 'Instances of this class can be cloned');

      if($class->isIterateable())
        $modifiers[] = array('iterateable', 'X', 'Instances of this class are iterateable');            
     
      $class = $this->modifiers($modifiers) . $this->entity('class', $this->anchor($class, 'class'), $class);
    }   

    return implode($this->entity('sep', ' :: '), array_reverse($classes));
  }



  /**
   * Generate function info
   *
   * @since   1.0
   * @param   string|object $func   Function name or reflector object
   * @return  string
   */
  protected function transformFunctionString($func){

    if(!($func instanceof \Reflector))
      $func = new \ReflectionFunction($func);

    return $this->entity('function', $this->anchor($func, 'function'), $func);
  }



  /**
   * Builds a report with information about $subject
   *
   * @since   1.0
   * @param   mixed $subject       Variable to query   
   * @return  string               Result
   */
  protected function transformSubject(&$subject){
  
    // null value
    if(is_null($subject))
      return $this->entity('null');

    // integer or double
    if(is_int($subject) || is_float($subject))
      return $this->entity(gettype($subject), $subject, gettype($subject));    

    // boolean
    if(is_bool($subject)){
      $text = $subject ? 'true' : 'false';
      return $this->entity($text, $text, gettype($subject));        
    }  

    // resource
    if(is_resource($subject)){

      $type = get_resource_type($subject);
      $name = $this->entity('resource', $subject);        
      $meta = array();

      // @see: http://php.net/manual/en/resource.php
      // need to add more...
      switch($type){

        // curl extension resource
        case 'curl':
          $meta = curl_getinfo($subject);
        break;

        case 'FTP Buffer':
          $meta = array(
            'time_out'  => ftp_get_option($subject, FTP_TIMEOUT_SEC),
            'auto_seek' => ftp_get_option($subject, FTP_AUTOSEEK),
          );

        break;

        // gd image extension resource
        case 'gd':
          $meta = array(
             'size'       => sprintf('%d x %d', imagesx($subject), imagesy($subject)),
             'true_color' => imageistruecolor($subject),
          );

        break;  

        case 'ldap link':
          $constants = get_defined_constants();

          array_walk($constants, function($value, $key) use(&$constants){
            if(strpos($key, 'LDAP_OPT_') !== 0)
              unset($constants[$key]);
          });

          // this seems to fail on my setup :(
          unset($constants['LDAP_OPT_NETWORK_TIMEOUT']);

          foreach(array_slice($constants, 3) as $key => $value)
            if(ldap_get_option($subject, (int)$value, $ret))
              $meta[strtolower(substr($key, 9))] = $ret;

        break;

        // mysql connection (mysql extension is deprecated from php 5.4/5.5)
        case 'mysql link':
        case 'mysql link persistent':
          $dbs = array();
          $query = @mysql_list_dbs($subject);
          while($row = @mysql_fetch_array($query))
            $dbs[] = $row['Database'];

          $meta = array(
            'host'             => ltrim(@mysql_get_host_info ($subject), 'MySQL host info: '),
            'server_version'   => @mysql_get_server_info($subject),
            'protocol_version' => @mysql_get_proto_info($subject),
            'databases'        => $dbs,
          );

        break;

        // mysql result
        case 'mysql result':
          while($row = @mysql_fetch_object($subject))
            $meta[] = (array)$row;

        break;

        // stream resource (fopen, fsockopen, popen, opendir etc)
        case 'stream':
          $meta = stream_get_meta_data($subject);
        break;

      }

      $items = array();
      $this->level++;      

      foreach($meta as $key => $value){
        $key = ucwords(str_replace('_', ' ', $key));
        $items[] = array(
          $this->entity('resourceInfo', $key),
          $this->entity('sep', ':'),
          $this->transformSubject($value),
        );
      }  

      $group = $name . $this->group($type, $this->section($items));
      $this->level--;

      return $group;
    }    

    // string
    if(is_string($subject)){
      $encoding = function_exists('mb_detect_encoding') ? mb_detect_encoding($subject) : false;
      $length   = static::strLen($subject);
      $info     = $length;
      $alpha    = ctype_alpha($subject);

      if($encoding)
        $info = $encoding . ' (' . $info . ')';

      $string = $this->entity('string', $subject, $info);
      $matches = array();

      // file?
      if(file_exists($subject)){

        $file  = new \SplFileInfo($subject);
        $info  = array();
        $perms = $file->getPerms();

        // socket
        if(($perms & 0xC000) === 0xC000)
          $info[] = 's';

        // symlink        
        elseif(($perms & 0xA000) === 0xA000)
          $info[] = 'l';

        // regular
        elseif(($perms & 0x8000) === 0x8000)
          $info[] = '-';

        // block special
        elseif(($perms & 0x6000) === 0x6000)
          $info[] = 'b';

        // directory
        elseif(($perms & 0x4000) === 0x4000)
          $info[] = 'd';

        // character special
        elseif(($perms & 0x2000) === 0x2000)          
          $info[] = 'c';

        // FIFO pipe
        elseif(($perms & 0x1000) === 0x1000)          
          $info[] = 'p';

        // unknown
        else          
          $info[] = 'u';        

        // owner
        $info[] = (($perms & 0x0100) ? 'r' : '-');
        $info[] = (($perms & 0x0080) ? 'w' : '-');
        $info[] = (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));

        // group
        $info[] = (($perms & 0x0020) ? 'r' : '-');
        $info[] = (($perms & 0x0010) ? 'w' : '-');
        $info[] = (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));

        // world
        $info[] = (($perms & 0x0004) ? 'r' : '-');
        $info[] = (($perms & 0x0002) ? 'w' : '-');
        $info[] = (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));
        
        $matches[] = $this->entity('strMatch', 'File') . ' ' . implode('', $info) . ' ' . sprintf('%.2fK', $file->getSize() / 1024);
      }  

      // class name?
      if(class_exists($subject, false))
        $matches[] = $this->entity('strMatch', 'Class') . ' ' . $this->transformClassString($subject);

      if(interface_exists($subject, false))
        $matches[] = $this->entity('strMatch', 'Interface') . ' ' . $this->transformClassString($subject);

      // class name?
      if(function_exists($subject))
        $matches[] = $this->entity('strMatch', 'Function') . ' ' . $this->transformFunctionString($subject);      

      // skip more advanced checks if the string appears to be numeric,
      // or if it's shorter than 5 characters
      if(!is_numeric($subject) && ($length > 4)){

        // date?
        if($length > 4){
          $date = date_parse($subject);
          if(($date !== false) && empty($date['errors']))
            $matches[] = $this->entity('strMatch', 'Date') . ' ' . static::humanTime(@strtotime($subject));
        }

        // attempt to detect if this is a serialized string     
        static $unserializing = false;      

        if(!$unserializing && in_array($subject[0], array('s', 'a', 'O'), true)){
          $unserializing = true;
          if(($subject[$length - 1] === ';') || ($subject[$length - 1] === '}'))
            if((($subject[0] === 's') && ($subject[$length - 2] !== '"')) || preg_match("/^{$subject[0]}:[0-9]+:/s", $subject))
              if(($unserialized = @unserialize($subject)) !== false)
                $matches[] = $this->entity('strMatch', 'Unserialized:') . ' ' . $this->transformSubject($unserialized);

          $unserializing = false;

        }else{

          // try to find out if it's a json-encoded string;
          // only do this for json-encoded arrays or objects, because other types have too generic formats
          static $decodingJson = false;

          if(!$decodingJson && in_array($subject[0], array('{', '['), true)){     
            $decodingJson = true;
            $json = json_decode($subject);

            if(json_last_error() === JSON_ERROR_NONE)
              $matches[] = $this->entity('strMatch', 'Json.Decoded') . ' ' . $this->transformSubject($json);

            $decodingJson = false;
          }
        }
      }

      if($matches)
        $string = $string . $this->entity('sep', "\n") . implode($this->entity('sep', "\n"), $matches);

      return $string;
    }    

    // arrays
    if(is_array($subject)){

      // empty array?
      if(empty($subject))      
        return $this->entity('array', 'Array') . $this->group();

      // set a marker to detect recursion
      if(!$this->arrayMarker)
        $this->arrayMarker = uniqid('', true);

      // if our marker element is present in the array it means that we were here before
      if(isset($subject[$this->arrayMarker]))
        return $this->entity('array', 'Array') . $this->group('Recursion');

      $subject[$this->arrayMarker] = true;

      // note that we must substract the marker element
      $itemCount = count($subject) - 1;
      $index = 0;
      $this->level++;      

      // note that we build the item list using splFixedArray() to save up some memory, because the subject array
      // might contain a huge amount of entries. A more efficient way is to build the items as we go as strings,
      // by concatenating the info foreach entry, but then we loose the flexibility that the
      // entity/group/section methods provide us (exporting data in different formats would become harder)
      $items = new \SplFixedArray($itemCount);

      foreach($subject as $key => &$value){

        // ignore our marker
        if($key === $this->arrayMarker)
          continue;

        if(is_string($key)){
          $encoding = function_exists('mb_detect_encoding') ? mb_detect_encoding($key) : 'ASCII';
          $length   = static::strLen($key);
          $keyInfo  = sprintf('String key (%s/%d)', $encoding, $length);
        
        }else{
          $keyInfo = sprintf('Numeric key (%s)', gettype($key));
        }

        $items[$index++] = array(
          $this->entity('key', $key, $keyInfo),
          $this->entity('sep', '=>'),
          $this->transformSubject($value),
        );
      }

      // remove our temporary marker;
      // not really required, because ::build() doesn't take references, but we want to be nice :P
      unset($subject[$this->arrayMarker]);

      $group = $this->entity('array', 'Array') . $this->group($itemCount, $this->section($items));
      $this->level--;

      return $group;
    }

    // if we reached this point, $subject must be an object     
    $objectName = $this->transformClassString($subject);
    $objectHash = spl_object_hash($subject);

    // already been here?
    if(in_array($objectHash, $this->objectHashes))
      return $this->entity('object', "{$objectName} Object") . $this->group('Recursion');

    // track hash
    $this->objectHashes[] = $objectHash;

    // again, because reflectionObjects can't be cloned apparently :)
    $reflector = new \ReflectionObject($subject);    

    $props      = $reflector->getProperties(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);    
    $methods    = $reflector->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED);
    $constants  = $reflector->getConstants();
    $interfaces = $reflector->getInterfaces();
    $traits     = (PHP_MINOR_VERSION > 3) ? $reflector->getTraits() : array();

    $internalParents = $this->getParentClasses($reflector, true);

    // no data to display?
    if(!$props && !$methods && !$constants && !$interfaces && !$traits)
      return $this->entity('object', "{$objectName} Object") . $this->group();

    $output = '';
    $this->level++;

    // display the interfaces this objects' class implements
    if($interfaces){

      // no splFixedArray here because we don't expect one zillion interfaces to be implemented by this object
      $intfNames = array();

      foreach($interfaces as $name => $interface)
        $intfNames[] = $this->entity('interface', $this->anchor($interface, 'class'), $interface);      

      $output .= $this->section(array((array)implode($this->entity('sep', ', '), $intfNames)), 'Implements');
    }

    // class constants
    if($constants){
      $itemCount = count($constants);
      $index = 0;
      $items = new \SplFixedArray($itemCount);

      foreach($constants as $name => $value){

        foreach($internalParents as $parent)
          if($parent->hasConstant($name))
            $name = $this->anchor($name, 'constant', $parent->getName(), $name);

        $items[$index++] = array(
          $this->entity('sep', '::'),
          $this->entity('constant', $name),
          $this->entity('sep', '='),
          $this->transformSubject($value),
        );
        
      }

      $output .= $this->section($items, 'Constants');    
    }

    // traits this objects' class uses
    if($traits){  
      $traitNames = array();
      foreach($traits as $name => $trait)
        $traitNames[] = $this->entity('trait', $trait->getName(), $trait);

      $output .= $this->section((array)implode(', ', $traitNames), 'Uses');
    }

    // object/class properties
    if($props){
      $itemCount = count($props);
      $index = 0;
      $items = new \SplFixedArray($itemCount);

      foreach($props as $prop){
        $modifiers = array();

        if($prop->isProtected())        
          $prop->setAccessible(true);

        $value = $prop->getValue($subject);

        if($prop->isProtected())        
          $prop->setAccessible(false);        

        if($prop->isProtected())
          $modifiers[] = array('protected', 'P', 'This property is protected');

        $name = $prop->name;

        foreach($internalParents as $parent)
          if($parent->hasProperty($name))
            $name = $this->anchor($name, 'property', $parent->getName(), $name);

        $items[$index++] = array(
          $this->entity('sep', $prop->isStatic() ? '::' : '->'),
          $this->modifiers($modifiers),
          $this->entity('property', $name, $prop),
          $this->entity('sep', '='),
          $this->transformSubject($value),
        );

      }

      $output .= $this->section($items, 'Properties');
    }

    // class methods
    if($methods){
      $index     = 0;
      $itemCount = count($methods);      
      $items     = new \SplFixedArray($itemCount);

      foreach($methods as $method){

        $paramStrings = $modifiers = array();

        // process arguments
        foreach($method->getParameters() as $parameter){

          $paramName = sprintf('$%s', $parameter->getName());

          if($parameter->isPassedByReference())
            $paramName = sprintf('&%s', $paramName);

          try{
            $paramClass = $parameter->getClass();

          // @see https://bugs.php.net/bug.php?id=32177&edit=1
          }catch(\Exception $e){

          }

          $paramHint = '';

          if($paramClass)
            $paramHint = $this->entity('hint', $this->anchor($paramClass->getName(), 'class'), $paramClass);
          
          if($parameter->isArray())
            $paramHint = $this->entity('arrayHint', 'Array');
       
          if($parameter->isOptional()){
            $paramValue = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;            
            $paramName  = $this->entity('param', $paramName, $parameter);
            $paramName .= $this->entity('sep', ' = ');
            $paramName .= $this->entity('paramValue', $this->transformSubject($paramValue));

            if($paramHint)
              $paramName = $paramHint . ' ' . $paramName;

            $paramName  = $this->entity('optional', $paramName);

          }else{            
            $paramName = $this->entity('param', $paramName, $parameter);

            if($paramHint)
              $paramName = $paramHint . ' ' . $paramName;            
          }

          $paramStrings[] = $paramName;
        }

        // is this method inherited?
        $inherited = $reflector->getShortName() !== $method->getDeclaringClass()->getShortName();
        $modTip    = $inherited ? sprintf('Inherited from ::%s', $method->getDeclaringClass()->getShortName()) : null;

        if($method->isAbstract())
          $modifiers[] = array('abstract', 'A', 'This method is abstract');

        if($method->isFinal())
          $modifiers[] = array('final', 'F', 'This method is final and cannot be overridden');

        if($method->isProtected())
          $modifiers[] = array('protected', 'P', 'This method is protected');        

        $name = $method->name;

        if($method->returnsReference())
          $name = '&' . $name;

        if($method->isInternal() && !(($method->class === 'Closure') && ($method->name === '__invoke')))
          $name = $this->anchor($name, 'method', $method->getDeclaringClass()->getName(), $name);          

        $name = $this->entity('method', $name, $method);

        if($inherited)
          $name = $this->entity('inherited', $name);

        $items[$index++] = array(
          $this->entity('sep', $method->isStatic() ? '::' : '->', $modTip),
          $this->modifiers($modifiers),
          $name . $this->entity('sep', ' (') . implode($this->entity('sep', ', '), $paramStrings) . $this->entity('sep', ')'),
        );       
      }

      $output .= $this->section($items, 'Methods');
    }

    $group = $this->entity('object', "{$objectName} Object") . $this->group('', $output);
    $this->level--;

    return $group;
  }



  /**
   * Scans for known classes and functions inside the provided expression,
   * and formats them when possible
   *
   * @since   1.0
   * @param   string $expression      Expression to format
   * @return  string                  Formatted output
   */
  public function transformExpression($expression){

    $prefix = $this->entity('sep', '> ');

    if(strpos($expression, '(') === false)
      return $this->entity('exp', $prefix . $expression);

    $fn = explode('(', $expression, 2);

    // try to find out if this is a function
    try{
      $reflector = new \ReflectionFunction($fn[0]);        

      $fn[0] = $this->entity('srcFunction', $this->anchor($reflector, 'function'), $reflector);
    
    }catch(\Exception $e){

      if(stripos($fn[0], 'new ') === 0){

        $cn = explode(' ' , $fn[0], 2);

        // linkify 'new keyword' (as constructor)
        try{          
          $reflector = new \ReflectionMethod($cn[1], '__construct');

          if($reflector->isInternal()){
            $cn[0] = $this->anchor($cn[0], 'method', $cn[1], '__construct');
            $cn[0] = $this->entity('srcClass', $cn[0], $reflector);
          }

        }catch(\Exception $e){
          $reflector = null;
        }            

        // class name...
        try{          
          $reflector = new \ReflectionClass($cn[1]);
          $cn[1] = $this->entity('srcClass', $this->anchor($reflector, 'class'), $reflector);

        }catch(\Exception $e){
          $reflector = null;
        }      

        $fn[0] = implode(' ', $cn);

      }else{

        if(strpos($expression, '::') === false)
          return $this->entity('exp', $prefix . $expression);

        $cn = explode('::', $fn[0], 2);

        // perhaps it's a static class method; try to linkify method first
        try{
          $reflector = new \ReflectionMethod($cn[0], $cn[1]);
          $cn[1] = $this->entity('srcMethod', $this->anchor($reflector, 'method', $cn[0], $cn[1]), $reflector);    

        }catch(\Exception $e){
          $reflector = null;
        }        

        // attempt to linkify the class name as well
        try{
          $reflector = new \ReflectionClass($cn[0]);
          $cn[0] = $this->entity('srcClass', $this->anchor($reflector, 'class'), $reflector);          

        }catch(\Exception $e){
          $reflector = null;
        }

        // apply changes
        $fn[0] = implode('::', $cn);
      }
    }

    return $this->entity('exp', $prefix . implode('(', $fn));
  }



  /**
   * Total CPU time used by the class
   *   
   * @since   1.0
   * @return  double
   */
  public static function getTime(){
    return static::$time;
  }



  /**
   * Creates the root structure that contains all the groups and entities
   *   
   * @since   1.0
   * @param   mixed $subject           Variable to query
   * @param   string|null $expression  Source expression string
   * @param   string|null $format      Output format, defaults to the 'outputFormat' config setting
   * @return  string                   Formatted information
   */
  public static function transform($subject, $expression = null, $format = null){

    // tracks style/jscript inclusion state (html only)
    static $didAssets = false;
 
    // instance index (gets displayed as comment in html-mode)
    static $counter = 1;


    if(!$format)
      $format = static::$outputFormat;

    //$errorReporting = error_reporting(0);

    $startTime = microtime(true);  
    $startMem  = memory_get_usage();
    $instance  = new static($format);
    $varOutput = $instance->transformSubject($subject);    
    $expOutput = ($expression !== null) ? $instance->transformExpression($expression) : '';
    $memUsage  = abs(round((memory_get_usage() - $startMem) / 1024, 2));
    $cpuUsage  = round(microtime(true) - $startTime, 4);

    static::$time += $cpuUsage;

    $instance = null;
    unset($instance);

    switch($format){

      // HTML output
      case 'html':
        $assets = '';      

        // first call? include styles and javascript
        if(!$didAssets){

          ob_start();

          if(static::$stylePath !== false){
            ?>
            <style scoped>
              <?php readfile(str_replace('{:dir}', __DIR__, static::$stylePath)); ?>
            </style>
            <?php
          }

          if(static::$scriptPath !== false){
            ?>
            <script>
              <?php readfile(str_replace('{:dir}', __DIR__, static::$scriptPath)); ?>
            </script>
            <?php
          }  

          // normalize space and remove comments
          $assets = preg_replace('/\s+/', ' ', trim(ob_get_clean()));
          $assets = preg_replace('!/\*.*?\*/!s', '', $assets);
          $assets = preg_replace('/\n\s*\n/', "\n", $assets);

          $didAssets = true;
        }

        $output = sprintf('<div class="ref">%s<div>%s</div></div>', $expOutput, $varOutput);

        return sprintf('<!-- ref #%d -->%s%s<!-- /ref (took %ss, %sK) -->', $counter++, $assets, $output, $cpuUsage, $memUsage);        

      // text output
      default:      
        return sprintf("\n%s\n%s\n%s\n", $expOutput, str_repeat('=', static::strLen($expOutput)), $varOutput);

    }

  }



  /**
   * Creates a group
   *
   * @since   1.0
   * @param   string $prefix
   * @param   string $toggledText
   * @return  string
   */
  protected function group($prefix = '', $content = ''){

    switch($this->format){

      // HTML output
      case 'html':

        if($content !== ''){
          $checked = (static::$expandDepth < 0) || ((static::$expandDepth > 0) && ($this->level <= static::$expandDepth)) ? 'checked="checked"' : '';
          $content = sprintf('<input type="checkbox" id="%1$s" %2$s/><label for="%1$s"></label><div>%3$s</div>', uniqid('x', true), $checked, $content);
        }  

        if($prefix !== '')
          $prefix = '<b>' . $prefix . '</b>';

        return '<i class="rGroup">(' . $prefix . '</i>' . $content . '<i class="rGroup">)</i>';        

      // text-only output
      default:

        if($content !== '')
          $content =  $content . "\n";

        return '(' . $prefix . $content . ')';
      
    }    
  }



  /**
   * Calculates real string length
   *
   * @since   1.0
   * @param   string $string
   * @return  int
   */
  protected static function strLen($string){

    if(function_exists('mb_strlen'))
      return mb_strlen($string, mb_detect_encoding($string));      
    
    return strlen($string);
  }



  /**
   * Safe str_pad alternative
   *
   * @since   1.0
   * @param   string $string
   * @param   int $padLen
   * @param   string $padStr
   * @param   int $padType
   * @return  string
   */
  protected static function strPad($input, $padLen, $padStr = ' ', $padType = STR_PAD_RIGHT){
    $diff = strlen($input) - static::strLen($input);
    return str_pad($input, $padLen + $diff, $padStr, $padType);
  }



  /**
   * Creates a group section
   *
   * @since   1.0
   * @param   array|splFixedArray $items    Array or SplFixedArray instance containing rows and columns (columns as arrays)
   * @param   string $title                 Section title, optional
   * @return  string
   */
  protected function section($items, $title = ''){

    switch($this->format){
      
      // HTML output
      case 'html':

        if($title !== '')
          $title = '<h4>' . $title .'</h4>';

        $content = '';

        foreach($items as $item){
          $last = array_pop($item);
          $defs = $item ? '<dt>' . implode('</dt><dt>', $item) . '</dt>' : '';
          $content .= '<dl>' . $defs . '<dd>' . $last . '</dd></dl>';
        }

        return $title . '<section>' . $content . '</section>';

      // text-only output
      default:

        $output = '';

        if($title !== '')
          $output .= "\n\n " . $title . "\n " . str_repeat('-', static::strLen($title));

        $lengths = array();

        // determine maximum column width
        foreach($items as $item)
          foreach($item as $colIdx => $c)
            if(!isset($lengths[$colIdx]) || $lengths[$colIdx] < static::strLen($c))
              $lengths[$colIdx] = static::strLen($c);

        foreach($items as $item){

          $lastColIdx = count($item) - 1;
          $padLen     = 0;
          $output    .= "\n  ";

          foreach($item as $colIdx => $c){

            // skip empty columns
            if($lengths[$colIdx] < 1)
              continue;

            if($colIdx < $lastColIdx){
              $output .= static::strPad($c, $lengths[$colIdx]) . ' ';
              $padLen += $lengths[$colIdx] + 1;
              continue;
            }
        
            $lines   = explode("\n", $c);
            $output .= array_shift($lines);

            // we must indent the entire block
            foreach($lines as &$line)
              $line = str_repeat(' ', $padLen) . $line;

            $output .= $lines ? "\n  " . implode("\n  ", $lines) : '';
          }         
        }

        return $output;
    }
  }



  /**
   * Generates an anchor that links to the documentation page relevant for the requested context
   *
   * For internal functions and classes, the URI will point to the local PHP manual
   * if installed and configured, otherwise to php.net/manual (the english one)
   *
   * @since   1.0   
   * @param   string|Reflector $text  Text to linky of reflector object to extract text from (name)
   * @param   string $scheme          Scheme to use; valid schemes are 'class', 'function', 'method', 'constant' (class only) and 'property'
   * @param   string $id1             Class or function name
   * @param   string|null $id2        Method name (required only for the 'method' scheme)
   * @return  string                  HTML link
   */
  protected function anchor($text, $scheme, $id1 = null, $id2 = null){

    static $docRefRoot = null, $docRefExt = null;

    $linkText = ($text instanceof \Reflector) ? $text->getName() : $text;

    if($id1 === null)
      $id1 = $linkText;

    // no links in text-mode, or if the class is a 'stdClass'
    if(($this->format !== 'html') || (($scheme !== 'function') && ($id1 === 'stdClass')))
      return $linkText;

    $args = array_filter(array($id1, $id2));

     // most people don't have this set
    if(!$docRefRoot)
      $docRefRoot = rtrim(ini_get('docref_root'), '/');

    if(!$docRefRoot)
      $docRefRoot = 'http://php.net/manual/en';

    if(!$docRefExt)
      $docRefExt = ini_get('docref_ext');

    if(!$docRefExt)
      $docRefExt = '.php';    

    if(($text instanceof \Reflector) && !$text->isInternal()){

      $uri = false;
      $sourceFile = $text->getFileName();      

      switch(true){      

        // WordPress function;
        // like pretty much everything else in WordPress, API links are inconsistent as well;
        // so we're using queryposts.com as doc source for API
        case ($scheme === 'function') && class_exists('WP') && defined('ABSPATH') && defined('WPINC'):

          if(strpos($sourceFile, realpath(ABSPATH . WPINC)) === 0){
            $uri = sprintf('http://queryposts.com/function/%s', urlencode(strtolower($text->getName())));
            break;
          }

        // @todo: handle more apps
      }

      if($uri)
        return sprintf('<a href="%s" target="_blank">%s</a>', $uri, static::escape($linkText));

      return $linkText;
    }

    // PHP-internal function or class
    foreach($args as &$arg)
      $arg = str_replace('_', '-', ltrim(strtolower($arg), '\\_'));

    $phpNetSchemes = array(
      'class'     => $docRefRoot . '/class.%s'    . $docRefExt,
      'function'  => $docRefRoot . '/function.%s' . $docRefExt,
      'method'    => $docRefRoot . '/%1$s.%2$s'   . $docRefExt,
      'constant'  => $docRefRoot . '/class.%1$s'  . $docRefExt . '#%1$s.constants.%2$s',
      'property'  => $docRefRoot . '/class.%1$s'  . $docRefExt . '#%1$s.props.%2$s',
    );

    $uri = vsprintf($phpNetSchemes[$scheme], $args);    

    return sprintf('<a href="%s" target="_blank">%s</a>', $uri, static::escape($linkText));
  }



  /**
   * Creates a single entity with the provided class, text and tooltip content
   *
   * @since   1.0
   * @param   string $class           Entity class ('r' will be prepended to it, then the entire thing gets camelized)
   * @param   string $text            Entity text content
   * @param   string|Reflector $tip   Tooltip content, or Reflector object from which to generate this content
   * @return  string                  <I> tag with the provided information
   */
  protected function entity($class, $text = null, $tip = null){

    if($text === null)
      $text = $class;

    // we can't show all tip content in text-mode
    if($this->format !== 'html'){

      switch($class){

        case 'string':
          return 'string(' . $this->tip($tip) . ') "' . $text . '"';

        case 'strMatch':        
          return '~ ' . $text . ':';

        case 'integer':
        case 'double':        
          return $this->tip($tip) . '(' . $text . ')';

        case 'key':
          return '[' . $text . ']';

      }

      return $text;
    }

    // escape text that is known to contain html entities
    if(in_array($class, array('string', 'key', 'param', 'sep', 'resourceInfo'), true))
      $text = static::escape($text);

    $tip = $this->tip($tip);
    
    $class = ucfirst($class);

    if($tip !== '')
      $class .= ' rHasTip';

    return sprintf('<i class="r%s">%s%s</i>', $class, $text, $tip);
  }



  /**
   * Escapes variable for HTML output
   *
   * @since   1.0
   * @param   mixed
   * @return  mixed
   */
  protected static function escape($var){
    return is_array($var) ? array_map('static::escape', $var) : htmlspecialchars($var, ENT_QUOTES);
  }



  /**
   * Creates a tooltip
   *
   * @todo    refactor
   * @since   1.0
   * @param   string|reflector $data     Tooltip content, or reflector object from where to extract DocBlock comments
   * @return  string
   */
  protected function tip($data){

    $text = $desc = $subMeta = $def = $leftMeta = '';

    // class or function
    if(($data instanceof \ReflectionClass) || ($data instanceof \ReflectionFunction) || ($data instanceof \ReflectionMethod)){

      // function/class/method is part of the core
      if($data->isInternal()){
        $extension = $data->getExtension();
        $text = ($extension instanceof \ReflectionExtension) ? sprintf('Internal - part of %s (%s)', $extension->getName(), $extension->getVersion()) : 'Internal';

      // user-defined; attempt to get doc comments
      }else{

        if($comments = static::parseComment($data->getDocComment())){
          $text = $comments['title'];
          $desc = $comments['description'];

          foreach($comments['tags'] as $tag => $values){
            foreach($values as $value){

              if($tag === 'param'){
                $value[0] = $value[0] . ' ' . $value[1];
                unset($value[1]);
              }

              $value = is_array($value) ? implode('</b><b>', static::escape($value)) : static::escape($value);

              $subMeta .= sprintf('<i><b>@%s</b><b>%s</b></i>', $tag, $value);
            }  
          }

        }

        $subMeta .= sprintf('<q>Defined in <b>%s:%d</b></q>', basename($data->getFileName()), $data->getStartLine());
      }

    // class property
    }elseif($data instanceof \ReflectionProperty){

      $comments = static::parseComment($data->getDocComment());

      if($comments){
        $text = $comments['title'];
        $desc = $comments['description'];

        // note that we need to make the left meta area have the same height as the content
        if(isset($comments['tags']['var'][0]))
          $leftMeta = $comments['tags']['var'][0][0] . str_repeat("\n", substr_count(implode("\n", array_filter(array($text, $desc))), "\n") + 1);
      }  
    
    // function parameter
    }elseif($data instanceof \ReflectionParameter){

      $function = $data->getDeclaringFunction();

      $tags = static::parseComment($function->getDocComment(), 'tags');
      $params = empty($tags['param']) ? array() : $tags['param'];
    
      foreach($params as $tag){
        list($types, $name, $description) = $tag;
        if(ltrim($name, '&$') === $data->getName()){
          $text = $description;

          if($types)
            $leftMeta = $types . str_repeat("\n", substr_count($text, "\n") + 1);

          break;
        }  
      }      
    
    }else{
      $text = (string)$data;
    }

    if($text && ($this->format === 'html')){

      if($subMeta)
        $subMeta = sprintf('<q>%s</q>', $subMeta);

      if($leftMeta)
        $leftMeta = sprintf('<b>%s</b>', static::escape($leftMeta));

      if($desc)
        $desc = sprintf('<i>%s</i>', static::escape($desc));

      $text = sprintf('<q>%s<i><i>%s</i>%s</i>%s</q>', $leftMeta, static::escape($text), $desc, $subMeta);
    }  

    return $text ? $text : '';
  }



  /**
   * Creates modifier bubbles
   *
   * @since   1.0
   * @param   string $class           Entity class ('r' will be prepended to it, then the entire thing gets camelized)
   * @param   string $text            Entity text content   
   * @return  string                  <I> tag with the provided information
   */
  protected function modifiers(array $modifiers){

    switch($this->format){
      case 'html':

        foreach($modifiers as &$modifier)
          $modifier = $this->entity($modifier[0], $modifier[1], $modifier[2]);
        
        return '<i class="rModifiers">' . implode('', $modifiers) . '</i>';

      default:
        foreach($modifiers as &$modifier)
          $modifier = '[' . $modifier[1] . '] ';

        return implode('', $modifiers);

    } 
  }



  /**
   * Determines the input expression(s) passed to the shortcut function
   *
   * @since   1.0
   * @param   array &$options   Optional, options to gather (from operators)
   * @return  array             Array of string expressions
   */
  public static function getInputExpressions(array &$options = null){    

    // used to determine the position of the current call,
    // if more ::build() calls were made on the same line
    static $lineInst = array();

    // pull only basic info with php 5.3.6+ to save some memory
    $trace = defined('DEBUG_BACKTRACE_IGNORE_ARGS') ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) : debug_backtrace();
    
    while($callee = array_pop($trace)){

      // extract only the information we neeed
      $calee = array_intersect_key($callee, array_fill_keys(array('file', 'function', 'line'), false));
      extract($calee);

      // skip, if the called function doesn't match the shortcut function name
      if(!$function || !preg_grep("/{$function}/i" , static::$shortcutFunc))
        continue;

      if(!$line || !$file)
        return array();
    
      $code     = file($file);
      $code     = $code[$line - 1]; // multiline expressions not supported!
      $instIndx = 0;
      $tokens   = token_get_all("<?php {$code}");

      // locate the caller position in the line, and isolate argument tokens
      foreach($tokens as $i => $token){

        // match token with our shortcut function name
        if(is_string($token) || ($token[0] !== T_STRING) || (strcasecmp($token[1], $function) !== 0))
          continue;

        // is this some method that happens to have the same name as the shortcut function?
        if(isset($tokens[$i - 1]) && is_array($tokens[$i - 1]) && in_array($tokens[$i - 1][0], array(T_DOUBLE_COLON, T_OBJECT_OPERATOR), true))
          continue;

        // find argument definition start, just after '('
        if(isset($tokens[$i + 1]) && ($tokens[$i + 1][0] === '(')){
          $instIndx++;

          if(!isset($lineInst[$line]))
            $lineInst[$line] = 0;

          if($instIndx <= $lineInst[$line])
            continue;

          $lineInst[$line]++;

          // gather options
          if($options !== null){
            $j = $i - 1;
            while(isset($tokens[$j]) && is_string($tokens[$j]) && in_array($tokens[$j], array('@', '+', '-', '!', '~')))
              $options[] = $tokens[$j--];
          }  
         
          $lvl = $index = $curlies = 0;
          $expressions = array();

          // get the expressions
          foreach(array_slice($tokens, $i + 2) as $token){

            if(is_array($token)){
              $expressions[$index][] = $token[1];
              continue;
            }

            if($token === '{')
              $curlies++;

            if($token === '}')
              $curlies--;        

            if($token === '(')
              $lvl++;

            if($token === ')')
              $lvl--;

            // assume next argument if a comma was encountered,
            // and we're not insde a curly bracket or inner parentheses
            if(($curlies < 1) && ($lvl === 0) && ($token === ',')){
              $index++;
              continue;
            }  

            // negative parentheses count means we reached the end of argument definitions
            if($lvl < 0){         
              foreach($expressions as &$expression)
                $expression = trim(implode('', $expression));

              return $expressions;
            }

            $expressions[$index][] = $token;      
          }

          break;
        }    
      }     
    }
  }



  /**
   * Executes a function the given number of times and returns the elapsed time.
   *
   * Keep in mind that the returned time includes function call
   * overhead (including microtime calls) x iteration count.   
   * This is why this is better suited for determining which of two ore more functions
   * is the fastest, rather than finding out how fast is a single function.
   *
   * @since   1.0
   * @param   int $iterations
   * @param   callable $function
   * @param   mixed &$output
   * @return  double
   */
  public static function timeFunc($iterations, $function, &$output = null){
    
    $time = 0;

    for($i = 0; $i < $iterations; $i++){
      $start = microtime(true);
      $output = call_user_func($function);
      $time += microtime(true) - $start;
    }
    
    return round($time, 4);
  }  



  /**
   * Parses a DocBlock comment into a data structure.
   *
   * @since   1.0
   * @link    http://pear.php.net/manual/en/standards.sample.php
   * @param   string $comment   Comment string
   * @param   string $key       Field to return (optional)
   * @return  array|string      Array containing all fields, or array/string with the contents of the requested field
   */
  public static function parseComment($comment, $key = null){

    $title       = '';
    $description = '';
    $tags        = array();
    $tag         = null;
    $pointer     = null;
    $padding     = false;

    foreach(array_slice(preg_split('/\r\n|\r|\n/', $comment), 1, -1) as $line){

      // drop any leading spaces
      $line = ltrim($line);

      // drop "* "
      if($line !== '')
        $line = substr($line, 2);      

      if(strpos($line, '@') === 0){
        $padding = false;        
        $pos     = strpos($line, ' ');
        $tag     = substr($line, 1, $pos - 1);
        $line    = trim(substr($line, $pos));

        // tags that have two or more values;
        // note that 'throws' may also have two values, however most people use it like "@throws ExceptioClass if whatever...",
        // which, if broken into two values, leads to an inconsistent description sentence...
        if(in_array($tag, array('global', 'param', 'return', 'var'))){
          $parts = array();

          if(($pos = strpos($line, ' ')) !== false){
            $parts[] = substr($line, 0, $pos);
            $line = ltrim(substr($line, $pos));

            if(($pos = strpos($line, ' ')) !== false){

              // we expect up to 3 elements in 'param' tags
              if(($tag === 'param') && in_array($line[0], array('&', '$'), true)){
                $parts[] = substr($line, 0, $pos);
                $parts[] = ltrim(substr($line, $pos));
                
              }else{
                if($tag === 'param')
                  $parts[] = '';

                $parts[] = ltrim($line);
              }

            }else{
              $parts[] = $line;
            }
          
          }else{
            $parts[] = $line;            
          }

          $parts += array_fill(0, ($tag !== 'param') ? 2 : 3, '');

          // maybe we should leave out empty (invalid) entries?
          if(array_filter($parts)){
            $tags[$tag][] = $parts;
            $pointer = &$tags[$tag][count($tags[$tag]) - 1][count($parts) - 1];
          }  

        // tags that have only one value (eg. 'link', 'license', 'author' ...)
        }else{
          $tags[$tag][] = trim($line);
          $pointer = &$tags[$tag][count($tags[$tag]) - 1];
        }

        continue;
      }

      // preserve formatting of tag descriptions, because
      // in some frameworks (like Lithium) they span across multiple lines
      if($tag !== null){

        $trimmed = trim($line);

        if($padding !== false){
          $trimmed = static::strPad($trimmed, static::strLen($line) - $padding, ' ', STR_PAD_LEFT);
          
        }else{
          $padding = static::strLen($line) - static::strLen($trimmed);
        }  

        $pointer .=  "\n{$trimmed}";
        continue;
      }
      
      // tag definitions have not started yet;
      // assume this is title / description text
      $description .= "\n{$line}";
    }
    
    $description = trim($description);

    // determine the real title and description by splitting the text
    // at the nearest encountered [dot + space] or [2x new line]
    if($description !== ''){
      $stop = min(array_filter(array(static::strLen($description), strpos($description, '. '), strpos($description, "\n\n"))));
      $title = substr($description, 0, $stop + 1);
      $description = trim(substr($description, $stop + 1));
    }
    
    $data = compact('title', 'description', 'tags');

    if(!array_filter($data))
      return null;

    if($key !== null)
      return isset($data[$key]) ? $data[$key] : null;

    return $data;
  }



  /**
   * Generates a human readable date string from a given timestamp
   *
   * @since    1.0
   * @param    int $timestamp      Date in UNIX time format
   * @param    int $currentTime    Optional. Use a custom date instead of the current time returned by the server
   * @return   string              Human readable date string
   */
  public static function humanTime($time, $currentTime = null){

    $prefix      = '-';
    $time        = (int)$time;
    $currentTime = $currentTime !== null ? (int)$currentTime : time();

    if($currentTime === $time)
      return 'now';

    // swap values if the given time occurs in the future,
    // or if it's higher than the given current time
    if($currentTime < $time){
      $time  ^= $currentTime ^= $time ^= $currentTime;
      $prefix = '+';
    }

    $units = array(
      'y' => 31536000,   // 60 * 60 * 24 * 365 seconds
      'm' => 2592000,    // 60 * 60 * 24 * 30
      'w' => 604800,     // 60 * 60 * 24 * 7
      'd' => 86400,      // 60 * 60 * 24
      'h' => 3600,       // 60 * 60
      'i' => 60,
      's' => 1,
    );

    foreach($units as $unit => $seconds)
      if(($count = (int)floor(($currentTime - $time) / $seconds)) !== 0)
        break;

    return $prefix . $count . $unit;
  }  

}
