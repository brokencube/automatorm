<?php
namespace Automatorm;

use Automatorm\Exception;

class OperatorParser
{
    public static function extractAffix($propertyName, $invert = false)
    {
        $parts = [];
        // Look for special non-alphanumeric affixes
        preg_match('/^([!=<>%#]*)([^!=<>%#]+)([!=<>%#]*)$/', $propertyName, $parts);
        $affix = $parts[1] ?: $parts[3];
        // Strip any affix from the property name
        $property = $parts[2];
        
        // Invert affix for not()
        switch ($affix) {
            case '=':
            case '==':
            default:
                $affix = $invert ? '!' : '=';
                break;
            case '!=':
            case '!':
            case '<>':
                $affix = $invert ? '=' : '!';
                break;
            case '<':
                $affix = $invert ? '>=' : '<';
                break;
            case '<=':
            case '=<':
                $affix = $invert ? '>' : '<='; 
                break;
            case '>':
                $affix = $invert ? '<=' : '>';
                break; 
            case '>=':
            case '=>':
                $affix = $invert ? '<' : '>=';
                break;
        }
        
        // These affixes only work in SQL land
        switch ($affix) {
            case '%':
            case '!%':
            case '%!':
            case '#':
                throw new Exception\BaseException('UNSUPPORTED_AFFIX_TYPE', $affix);
        }
        
        return [$affix, $property];
    }
    
    public static function testOperator($operator, $value1, $value2)
    {
        switch ($operator) {
            case '=':
                return $value1 == $value2;
            
            case '!':
                return $value1 != $value2;
            
            case '>':
                return $value1 > $value2;
            
            case '>=':
                return $value1 >= $value2;

            case '<':
                return $value1 < $value2;

            case '<=':
                return $value1 <= $value2;
            
            default:
                throw new Exception\BaseException('UNKNOWN_OPERATOR_PASSED', $operator);
        }
    }
}
