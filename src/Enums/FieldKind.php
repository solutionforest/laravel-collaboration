<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Enums;

/** How a field is shared while several people edit it. */
enum FieldKind: string
{
    /** Characters merge individually. */
    case Text = 'text';

    /** Characters, blocks and formatting merge individually. */
    case RichText = 'rich';

    /** The whole value is replaced by the most recent change. */
    case Value = 'value';

    /** Repeaters and builders: items merge like text, each sub-field by latest value. */
    case Items = 'items';

}
