<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * MATCH_AGAINST(e.a, e.b, …, :query): MySQL's full-text search in boolean mode, so a DQL query can filter by it
 * together with its other conditions (ExchangeRepository::searchText). MySQL only.
 */
final class MatchAgainst extends FunctionNode
{
    /** @var list<Node> */
    private array $columns = [];
    private Node $query;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->columns[] = $parser->StateFieldPathExpression();
        while (true) {
            $parser->match(TokenType::T_COMMA);
            if ($parser->getLexer()->isNextToken(TokenType::T_INPUT_PARAMETER)) {
                break;
            }
            $this->columns[] = $parser->StateFieldPathExpression();
        }
        $this->query = $parser->InParameter();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('MATCH (%s) AGAINST (%s IN BOOLEAN MODE)',
            implode(', ', array_map(static fn (Node $column) => $column->dispatch($sqlWalker), $this->columns)),
            $this->query->dispatch($sqlWalker));
    }
}
