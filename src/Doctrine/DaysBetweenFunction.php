<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DAYS_BETWEEN(later, earlier) — maps to PostgreSQL's `(later - earlier)`, which
 * yields an integer number of days when both operands are DATE columns.
 */
class DaysBetweenFunction extends FunctionNode
{
    private Node $later;
    private Node $earlier;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->later = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->earlier = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return '(' . $this->later->dispatch($sqlWalker) . ' - ' . $this->earlier->dispatch($sqlWalker) . ')';
    }
}
