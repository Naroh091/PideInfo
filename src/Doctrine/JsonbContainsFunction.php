<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * JSONB_CONTAINS(field, value) — maps to PostgreSQL's `field @> value::jsonb`.
 */
class JsonbContainsFunction extends FunctionNode
{
    private Node $field;
    private Node $value;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->value = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return '(' . $this->field->dispatch($sqlWalker) . ' @> ' . $this->value->dispatch($sqlWalker) . '::jsonb)';
    }
}
