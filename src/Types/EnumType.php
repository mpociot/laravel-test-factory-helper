<?php

namespace Mpociot\LaravelTestFactoryHelper\Types;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * My custom datatype.
 */
class EnumType extends Type
{

    const ENUM = 'enum'; // modify to match your type name

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        // this is wrong, but it also isn't important, since we're not modifying tables anyways. (this method must exist to match the abstract parent class, but we won't use it)
        return 'ENUM';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        return $value;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        return $value;
    }

    public function getName()
    {
        return self::ENUM;
    }
}
