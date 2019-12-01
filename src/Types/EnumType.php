<?php

namespace Mpociot\LaravelTestFactoryHelper\Types;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class EnumType extends Type
{

    const ENUM = 'enum';

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        /* 
         * This is wrong, but it also isn't important, since we're not modifying
         * tables anyways. This method must exist to match the abstract parent
         * class, but we won't use it. Normally, this should return the SQL 
         * required to create an 'enum' column, but since this function doesn't
         * know the values of the enum field, that's not possible.
         */
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
