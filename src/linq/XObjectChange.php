<?php

declare(strict_types=1);

namespace Light\Xml\Linq;

enum XObjectChange
{
    case Add;
    case Remove;
    case Name;
    case Value;
}
