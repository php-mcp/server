<?php

namespace Mcp\ComplexSchemaHttpExample\Model;

enum EventType: string
{
    case Meeting = 'meeting';
    case Reminder = 'reminder';
    case Call = 'call';
    case Other = 'other';
}

enum EventPriority: int
{
    case Low = 0;
    case Normal = 1;
    case High = 2;
}
