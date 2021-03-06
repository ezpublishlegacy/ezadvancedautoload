<?php
// NOTE : private is a reserved keyword
namespace extension\ezadvancedautoload\pv\classes {

    // Start requiring classes. Needed if it's first autoload run
    use extension\ezadvancedautoload\classes\helpers\advancedAutoloadHelper;

    if (!class_exists('\\eZAutoloadGenerator')) {
        require_once( __DIR__ . '/../../../../kernel/private/classes/ezautoloadgenerator.php' );
    }
    if (!class_exists('\\eZExtension')) {
        require_once( __DIR__ . '/../../../../lib/ezutils/classes/ezextension.php' );
    }
    if (!class_exists('\\eZINI')) {
        require_once( __DIR__ . '/../../../../lib/ezutils/classes/ezini.php' );
    }
    if (!class_exists('extension\\ezadvancedautoload\\classes\\helpers\\advancedAutoloadHelper')) {
        require_once( __DIR__ . '/../../../../extension/ezadvancedautoload/classes/helpers/advancedautoloadhelper.php' );
    }
    //

    /**
     * @brief File containing the eZAutoloadGenerator class.
     * @details This class permits to generate autoload array without considering unactivated extensions
     *
     * @author Adrien Loyant <adrien.loyant@te-laval.fr>
     *
     * @date 2012-03-01
     * @version 1.0.0
     * @since 1.0.0
     * @copyright GNU Public License v.2
     *
     * @package extension\ezadvancedautoload\pv\classes
     * @see \eZAutoloadGenerator
     *
     */
    class eZAutoloadGenerator extends \eZAutoloadGenerator {

        /**
         * @brief Actives extensions in eZPublish
         * @details List of the activated extension for current instance of eZPublish
         *
         * @var array
         */
        protected $activeExtensions;

        /**
         * @brief Constructs class to generate autoload arrays.
         * @details Constructor
         *
         * @param ezpAutoloadGeneratorOptions $options
         * @return void
         */
        public function __construct( \ezpAutoloadGeneratorOptions $options = null ) {
            $this->activeExtensions = \eZExtension::activeExtensions();
            parent::__construct($options);
        }

        /**
         * @brief Builds a filelist of PHP files in $path.
         * @details Builds a filelist array of PHP files in $path. Use the static keyword for
         * <a href="http://php.net/manual/language.oop5.late-static-bindings.php">late static binding</a>
         *
         * @param string $path
         * @param array $extraFilter
         * @return array
         */
        protected function buildFileList( $path, $extraFilter = null ) {
            if (advancedAutoloadHelper::isFinerFilterEnabled()) {
                $dirSep = preg_quote( DIRECTORY_SEPARATOR );
                $exclusionFilter = array( "@^{$path}{$dirSep}(var|settings|benchmarks|bin|autoload|port_info|update|templates|tmp|UnitTest|lib{$dirSep}ezc){$dirSep}@" );

                if ( !empty( $extraFilter ) && is_array( $extraFilter ) ) {
                    foreach( $extraFilter as $filter ) {
                        $exclusionFilter[] = $filter;
                    }
                }

                if (!empty( $path ) ) {
                    return static::findRecursive( $path, array( '@\.php$@' ), $exclusionFilter, $this );
                }
                return false;
            } else {
                return parent::buildFileList( $path, $extraFilter );
            }
        }

        /**
         * @brief Walker to find file
         * @details Uses the walker in ezcBaseFile to find files.
         * This also uses the callback to get progress information about the file search.
         *
         * @param string $sourceDir
         * @param array $includeFilters
         * @param array $excludeFilters
         * @param \eZAutoloadGenerator $gen
         * @return array
         */
        public static function findRecursive( $sourceDir, array $includeFilters = array(), array $excludeFilters = array(), \eZAutoloadGenerator $gen ) {
            if (advancedAutoloadHelper::isFinerFilterEnabled()) {
                $gen->log( 'Scanning for PHP-files.' );
                $gen->startProgressOutput( self::OUTPUT_PROGRESS_PHASE1 );

                // create the context, and then start walking over the array
                $context = new \ezpAutoloadFileFindContext();
                $context->generator = $gen;

                $callback = array( __NAMESPACE__.'\eZAutoloadGenerator', 'findRecursiveCallback' );
                static::walkRecursive( $sourceDir, $includeFilters, $excludeFilters, $callback, $context );

                // return the found and pattern-matched files
                sort( $context->elements );

                $gen->stopProgressOutput( self::OUTPUT_PROGRESS_PHASE1 );
                $gen->log( "Scan complete. Found {$context->count} PHP files." );

                return $context->elements;
            } else {
                return parent::findRecursive( $sourceDir, $includeFilters, $excludeFilters, $gen );
            }
        }

        /**
         * @brief Callback used ezcBaseFile
         * @details Callback function used by @ref eZAutoloadGenerator::findRecursive method
         *
         * @param string $ezpAutoloadFileFindContext
         * @param string $sourceDir
         * @param string $fileName
         * @param string $fileInfo
         * @return void
         */
        public static function findRecursiveCallback( \ezpAutoloadFileFindContext $context, $sourceDir, $fileName, $fileInfo ) {
            if (advancedAutoloadHelper::isFinerFilterEnabled()) {
                if ( !($fileInfo['mode'] & 0x4000) ) {
                    // check if we need to add element into the internal array
                    $activeModes = $context->generator->checkMode();
                    foreach( $activeModes as $modusOperandi ) {
                        switch( $modusOperandi ) {
                            case self::MODE_EXTENSION:
                            case self::MODE_KERNEL_OVERRIDE:
                                $extensionName = advancedAutoloadHelper::getExtensionName($sourceDir);
                                if ( !in_array($extensionName, $context->generator->activeExtensions) ) {
                                    $context->generator->updateProgressOutput( eZAutoloadGenerator::OUTPUT_PROGRESS_PHASE1 );
                                    return;
                                }
                                break;
                            default:
                                // continue
                                break;
                        }
                    }

                    // update the statistics
                    $context->elements[] = $sourceDir . DIRECTORY_SEPARATOR . $fileName;
                    $context->count++;

                    $context->generator->updateProgressOutput( eZAutoloadGenerator::OUTPUT_PROGRESS_PHASE1 );
                }
            } else {
                parent::findRecursiveCallBack( $context, $sourceDir, $fileName, $fileInfo );
            }
        }

        /**
         * @brief Internal method used to check if an class exist autoload arrays.
         * @details Internal method used to check if an class exist autoload arrays.
         * If it already exist then it check the priority with the active extensions.
         *
         * @param string $class The name of the class being checked.
         * @param int $checkMode The mode whose autoload arrays will be checked.
         * @param string $file Filename containing the class.
         * @param array $inProgressAutoloadArray The autoload array generated so far.
         * @param int $generatingMode The mode we are generating for autoloads for.
         * @return boolean
         */
        protected function classExistsInArray( $class, $checkMode, $file, $inProgressAutoloadArray = null, $generatingMode = null ) {
            $result = false;

            if (advancedAutoloadHelper::isFinerFilterEnabled()) {
                // set the array to check with
                if ( ( $checkMode === $generatingMode ) && !is_null($inProgressAutoloadArray) ) {
                    $arrayToCheck = $inProgressAutoloadArray;
                } else {
                    $arrayToCheck = $this->existingAutoloadArrays[$checkMode];
                }

                $classCollision = array_key_exists( $class, $arrayToCheck );

                if ($classCollision) {
                    $fileExtensionName = advancedAutoloadHelper::getExtensionName($file);
                    $collisionExtensionName = advancedAutoloadHelper::getExtensionName($inProgressAutoloadArray[$class]);
                    $activeExtensionsReverse = array_flip($this->activeExtensions);

                    // if class exist but the current priority is higher then the old one
                    if (isset($activeExtensionsReverse[$collisionExtensionName]) && isset($activeExtensionsReverse[$fileExtensionName])
                            && (int)$activeExtensionsReverse[$collisionExtensionName] < (int)$activeExtensionsReverse[$fileExtensionName] ) {
                        $message = "Class {$class}";
                        $message .= " in file {$file}";
                        $message .= " will override:\n";
                        $message .= "{$arrayToCheck[$class]}";
                        $this->log( $message );
                    } else {
                        $result = true;
                    }
                }

                // If there is a class collisions we want to give feedback to the user.
                if ( $result ) {
                    $this->logIssue( $class, $checkMode, $file, $inProgressAutoloadArray, $generatingMode );
                }
            } else {
                $result = parent::classExistsInArray( $class, $checkMode, $file, $inProgressAutoloadArray, $generatingMode );
            }

            return $result;
        }

    }

}
