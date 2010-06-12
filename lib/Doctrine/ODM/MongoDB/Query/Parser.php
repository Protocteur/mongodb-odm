<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ODM\MongoDB\Query;

use Doctrine\ODM\MongoDB\Query,
    Doctrine\ODM\MongoDB\DocumentManager;

/**
 * A simple parser for MongoDB Document Query Language
 *
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @since       1.0
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 */
class Parser
{
    /**
     * The DocumentManager instance for this query
     *
     * @var Doctrine\ODM\MongoDB\DocumentManager
     */
    private $_dm;

    /**
     * The lexer.
     *
     * @var Doctrine\ODM\MongoDB\Query\Lexer
     */
    private $_lexer;

    public function __construct(DocumentManager $dm)
    {
        $this->_dm = $dm;
        $this->_lexer = new Lexer;
    }

    public function parse($query, $parameters = array())
    {
        if (strpos($query, '?') !== false) {
            if (strpos($query, ':') !== false) {
                throw new \InvalidArgumentException('Cannot mixed named and regular placeholders.');
            }
            $regex = '/([=,\(][^\\\']*)(\?)/iU';
            foreach($parameters as $key => $value) {
                $query = preg_replace($regex, "\\1'{$value}'", $query, 1);
            }
        }

        $this->_lexer->reset();
        $this->_lexer->setInput($query);

        $query = $this->QueryLanguage($parameters);

        return $query;
    }

    /**
     * Attempts to match the given token with the current lookahead token.
     * If they match, updates the lookahead token; otherwise raises a syntax error.
     *
     * @param int|string token type or value
     * @return bool True if tokens match; false otherwise.
     */
    public function match($token)
    {
        if ( ! ($this->_lexer->lookahead['type'] === $token)) {
            $this->syntaxError($this->_lexer->getLiteral($token));
        }
        $this->_lexer->moveNext();
    }

    /**
     * Generates a new syntax error.
     *
     * @param string $expected Expected string.
     * @param array $token Optional token.
     * @throws AnnotationException
     */
    public function syntaxError($expected, $token = null)
    {
        if ($token === null) {
            $token = $this->_lexer->lookahead;
        }

        $message =  "Expected {$expected}, got ";

        if ($this->_lexer->lookahead === null) {
            $message .= 'end of string';
        } else {
            $message .= "'{$token['value']}' at position {$token['position']}";
        }

        $message .= '.';

        throw new \Doctrine\ODM\MongoDB\MongoDBException($message);
    }

    /**
     * QueryLanguage ::= FindQuery | InsertQuery | UpdateQuery | RemoveQuery
     */
    public function QueryLanguage(array $parameters)
    {
        $this->_lexer->moveNext();

        $query = new Query($this->_dm);

        switch ($this->_lexer->lookahead['type']) {
            case Lexer::T_FIND:
                $this->FindQuery($query, $parameters);
                break;
            case Lexer::T_INSERT:
                $this->InsertQuery($query, $parameters);
                break;
            case Lexer::T_UPDATE:
                $this->UpdateQuery($query, $parameters);
                break;
            case Lexer::T_REMOVE:
                $this->RemoveQuery($query, $parameters);
                break;
            default:
                $this->syntaxError('FIND, INSERT, UPDATE or REMOVE');
                break;
        }
        return $query;
    }

    /**
     * FindQuery ::= FindClause [WhereClause] [MapClause] [ReduceClause] [SortClause] [LimitClause] [SkipClause]
     */
    public function FindQuery(Query $query, array $parameters)
    {
        $this->FindClause($query);

        if ($this->_lexer->isNextToken(Lexer::T_WHERE)) {
            $this->WhereClause($query, $parameters);
        }

        if ($this->_lexer->isNextToken(Lexer::T_MAP)) {
            $this->MapClause($query, $parameters);
        }

        if ($this->_lexer->isNextToken(Lexer::T_REDUCE)) {
            $this->ReduceClause($query, $parameters);
        }
        
        $tokens = array(
            Lexer::T_SORT           => 'SortClause',
            Lexer::T_LIMIT          => 'LimitClause',
            Lexer::T_SKIP           => 'SkipClause'
        );

        while (true) {
            $found = false;
            foreach ($tokens as $token => $method) {
                if ($this->_lexer->isNextToken($token)) {
                    $this->match($token);
                    $found = true;
                    $this->$method($query, $parameters);
                    unset($tokens[$token]);
                }
            }
            if ($found === false) {
                break;
            }
        }
    }

    /**
     * FindClause ::= "FIND" all | SelectField {"," SelectField}
     */
    public function FindClause(Query $query)
    {
        $this->match(Lexer::T_FIND);

        if ($this->_lexer->isNextToken(Lexer::T_FIND_ALL)) {
            $this->match(Lexer::T_FIND_ALL);
        } else {
            $this->SelectField($query);
            while ($this->_lexer->isNextToken(Lexer::T_COMMA)) {
                $this->match(Lexer::T_COMMA);
                $this->SelectField($query);
            }
        }

        $this->match(Lexer::T_IDENTIFIER);
        $query->find($this->_lexer->token['value']);
    }

    /**
     * SelectField ::= DocumentFieldName
     */
    public function SelectField(Query $query)
    {
        $this->match(Lexer::T_IDENTIFIER);
        $query->addSelect($this->_lexer->token['value']);
    }

    /**
     * UpdateQuery ::= UpdateClause [SetExpression], [UnsetExpression], [IncrementExpression],
     *                              [PushExpression], [PushAllExpression], [PullExpression],
     *                              [PullAllExpression], [AddToSetExpression], [AddManyToSetExpression],
     *                              [PopFirstExpression], [PopLastExpression] [WhereClause]
     */
    public function UpdateQuery(Query $query, array $parameters)
    {
        $this->match(Lexer::T_UPDATE);
        $this->match(Lexer::T_IDENTIFIER);
        $query->update($this->_lexer->token['value']);

        $tokens = array(
            Lexer::T_SET            => 'SetExpression',
            Lexer::T_UNSET          => 'UnsetExpression',
            Lexer::T_INC            => 'IncrementExpression',
            Lexer::T_PUSH           => 'PushExpression',
            Lexer::T_PUSHALL        => 'PushAllExpression',
            Lexer::T_PULL           => 'PullExpression',
            Lexer::T_PULLALL        => 'PullAllExpression',
            Lexer::T_ADDTOSET       => 'AddToSetExpression',
            Lexer::T_ADDMANYTOSET   => 'AddManyToSetExpression',
            Lexer::T_POPFIRST       => 'PopFirstExpression',
            Lexer::T_POPLAST        => 'PopLastExpression'
        );

        $this->match($this->_lexer->lookahead['type']);
        $method = $tokens[$this->_lexer->token['type']];
        $this->$method($query, $parameters);

        while ($this->_lexer->isNextToken(Lexer::T_COMMA)) {
            $this->match(Lexer::T_COMMA);
            $this->match($this->_lexer->lookahead['type']);
            $method = $tokens[$this->_lexer->token['type']];
            $this->$method($query, $parameters);
        }

        if ($this->_lexer->isNextToken(Lexer::T_WHERE)) {
            $this->WhereClause($query, $parameters);
        }
    }

    /**
     * InsertQuery ::= InsertClause InsertSetClause {"," InsertSetClause}
     */
    public function InsertQuery(Query $query, array $parameters)
    {
        $this->match(Lexer::T_INSERT);
        $this->match(Lexer::T_IDENTIFIER);
        $query->insert($this->_lexer->token['value']);

        $this->match(Lexer::T_SET);
        $this->InsertSetClause($query, $parameters);
        while ($this->_lexer->isNextToken(Lexer::T_COMMA)) {
            $this->match(Lexer::T_COMMA);
            $this->InsertSetClause($query, $parameters);
        }
    }

    /**
     * InsertSetClause ::= DocumentFieldName "=" NewValue
     */
    public function InsertSetClause(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->set($fieldName, $value, false);
    }

    /**
     * RemoveQuery ::= RemoveClause [WhereClause]
     * RemoveClause ::= "REMOVE" DocumentClassName
     */
    public function RemoveQuery(Query $query, array $parameters)
    {
        $this->match(Lexer::T_REMOVE);
        $this->match(Lexer::T_IDENTIFIER);
        $query->remove($this->_lexer->token['value']);

        if ($this->_lexer->isNextToken(Lexer::T_WHERE)) {
            $this->WhereClause($query, $parameters);
        }
    }

    /**
     * SortClause ::= SortClauseField {"," SortClauseField}
     */
    public function SortClause(Query $query)
    {
        $this->SortClauseField($query);
        while ($this->_lexer->isNextToken(Lexer::T_COMMA)) {
            $this->match(Lexer::T_COMMA);
            $this->SortClauseField($query);
        }
    }

    /**
     * SortClauseField ::= DocumentFieldName "ASC | DESC"
     */
    public function SortClauseField(Query $query)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match(Lexer::T_IDENTIFIER);
        $order = $this->_lexer->token['value'];
        $query->addSort($fieldName, $order);
    }

    /**
     * LimitClause ::= "LIMIT" LimitInteger
     */
    public function LimitClause(Query $query)
    {
        $this->match($this->_lexer->lookahead['type']);
        $query->limit($this->_lexer->token['value']);
    }

    /**
     * SkipClause ::= "SKIP" SkipInteger
     */
    public function SkipClause(Query $query)
    {
        $this->match($this->_lexer->lookahead['type']);
        $query->skip($this->_lexer->token['value']);
    }

    /**
     * MapClause ::= "MAP" MapFunction
     */
    public function MapClause(Query $query, array $parameters)
    {
        $this->match(Lexer::T_MAP);
        $this->match(Lexer::T_STRING);
        $query->map($this->_lexer->token['value']);
    }

    /**
     * ReduceClause ::= "REDUCE" ReduceFunction
     */
    public function ReduceClause(Query $query, array $parameters)
    {
        $this->match(Lexer::T_REDUCE);
        $this->match(Lexer::T_STRING);
        $query->reduce($this->_lexer->token['value']);
    }

    /**
     * DocumentFieldName ::= DocumentFieldName | EmbeddedDocument "." {"." DocumentFieldName}
     */
    public function DocumentFieldName(Query $query)
    {
        $this->match(Lexer::T_IDENTIFIER);
        $fieldName = $this->_lexer->token['value'];
        while ($this->_lexer->isNextToken(Lexer::T_DOT)) {
            $this->match(Lexer::T_DOT);
            $this->match(Lexer::T_IDENTIFIER);
            $fieldName .= '.' . $this->_lexer->token['value'];
        }
        return $fieldName;
    }

    /**
     * WhereClause ::= "WHERE" WhereClausePart {"AND" WhereClausePart}
     */
    public function WhereClause(Query $query, array $parameters)
    {
        $this->match(Lexer::T_WHERE);
        $this->WhereClauseExpression($query, $parameters);
        while ($this->_lexer->isNextToken(Lexer::T_AND)) {
            $this->match(Lexer::T_AND);
            $this->WhereClauseExpression($query, $parameters);
        }
    }

    /**
     * WhereClausePart ::= DocumentFieldName WhereClauseExpression NewValue
     * WhereClauseExpression ::= "=" | "!=" | ">=" | "<=" | ">" | "<" | "in"
     *                         "notIn" | "all" | "size" | "exists" | "type"
     */
    public function WhereClauseExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $operator = $this->_lexer->token['value'];
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_lexer->token['value'];
        $operators = array(
            '='      => 'addWhere',
            '!='     => 'whereNotEqual',
            '>='     => 'whereGte',
            '<='     => 'whereLte',
            '>'      => 'whereGt',
            '<'      => 'whereLt',
            'in'     => 'whereIn',
            'notIn'  => 'whereNotIn',
            'all'    => 'whereAll',
            'size'   => 'whereSize',
            'exists' => 'whereExists',
            'type'   => 'whereType'
        );
        $method = $operators[$operator];
        $value = $this->_prepareValue($value, $parameters);
        $query->$method($fieldName, $value);
    }

    /**
     * SetExpression ::= "SET" DocumentFieldName "=" NewValue {"," SetExpression}
     */
    public function SetExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->set($fieldName, $value);
    }

    /**
     * UnsetExpression ::= "UNSET" DocumentFieldName {"," UnsetExpression}
     */
    public function UnsetExpression(Query $query, array $parameters)
    {
        $this->match(Lexer::T_IDENTIFIER);
        $query->unsetField($this->_lexer->token['value']);
    }

    /**
     * PushExpression ::= "PUSH" DocumentFieldName Value {"," PushExpression}
     */
    public function PushExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->push($fieldName, $value);
    }

    /**
     * PushAllExpression ::= "PUSHALL" DocumentFieldName Value {"," PushAllExpression}
     */
    public function PushAllExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->pushAll($fieldName, $value);
    }

    /**
     * PullExpression ::= "PULL" DocumentFieldName Value {"," PullExpression}
     */
    public function PullExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->pull($fieldName, $value);
    }

    /**
     * PullAllExpression ::= "PULLALL" DocumentFieldName Value {"," PullAllExpression}
     */
    public function PullAllExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->pullAll($fieldName, $value);
    }

    /**
     * PopFirstExpression ::= "POPFIRST" DocumentFieldName {"," PopFirstExpression}
     */
    public function PopFirstExpression(Query $query, array $parameters)
    {
        $this->match(Lexer::T_IDENTIFIER);
        $query->popFirst($this->_lexer->token['value']);
    }

    /**
     * PopLastExpression ::= "POPLAST" DocumentFieldName {"," PopLastExpression}
     */
    public function PopLastExpression(Query $query, array $parameters)
    {
        $this->match(Lexer::T_IDENTIFIER);
        $query->popLast($this->_lexer->token['value']);
    }

    /**
     * AddToSetExpression ::= "ADDTOSET" DocumentFieldName Value {"," AddToSetExpression}
     */
    public function AddToSetExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->addToSet($fieldName, $value);
    }

    /**
     * AddManyToSetExpression ::= "ADDMANYTOSET" DocumentFieldName Value {"," AddManyToSetExpression}
     */
    public function AddManyToSetExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->addManyToSet($fieldName, $value);
    }

    /**
     * IncrementExpression ::= "INC" DocumentFieldName "=" IncrementInteger {"," IncrementExpression}
     */
    public function IncrementExpression(Query $query, array $parameters)
    {
        $fieldName = $this->DocumentFieldName($query);
        $this->match($this->_lexer->lookahead['type']);
        $this->match($this->_lexer->lookahead['type']);
        $value = $this->_prepareValue($this->_lexer->token['value'], $parameters);
        $query->inc($fieldName, $value);
    }

    private function _prepareValue($value, array $parameters)
    {
        if (isset($parameters[$value])) {
            $value = $parameters[$value];
        }
        if ($value === 'true') {
            $value = true;
        }
        if ($value === 'false') {
            $value = false;
        }
        if (is_string($value) && strstr($value, 'json:') !== false) {
            $value = json_decode(substr($value, 5));
        }
        return $value;
    }
}