<?php

namespace Jackalope\Transport\DoctrineDBAL\Query;

use Jackalope\NotImplementedException;
use Jackalope\Query\QOM\PropertyValue;
use Jackalope\Query\QOM\QueryObjectModel;
use Jackalope\Transport\DoctrineDBAL\RepositorySchema;
use Jackalope\Transport\DoctrineDBAL\Util\Xpath;
use PHPCR\NamespaceException;
use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Query\InvalidQueryException;
use PHPCR\Query\QOM;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;

/**
 * Converts QOM to SQL Statements for the Doctrine DBAL database backend.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
class QOMWalker
{
    /**
     * @var NodeTypeManagerInterface
     */
    private $nodeTypeManager;

    /**
     * @var array
     */
    private $alias = array();

    /**
     * @var QOM\SelectorInterface
     */
    private $source;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $conn;

    /**
     * @var AbstractPlatform
     */
    private $platform;

    /**
     * @var array
     */
    private $namespaces;

    /**
     * @var \Doctrine\DBAL\Schema\Schema
     */
    private $schema;

    /**
     * @param NodeTypeManagerInterface $manager
     * @param Connection               $conn
     * @param array                    $namespaces
     */
    public function __construct(NodeTypeManagerInterface $manager, Connection $conn, array $namespaces = array())
    {
        $this->conn = $conn;
        $this->nodeTypeManager = $manager;
        $this->platform = $conn->getDatabasePlatform();
        $this->namespaces = $namespaces;
        $this->schema = new RepositorySchema(array(), $this->conn);
    }

    /**
     * Generate a table alias
     *
     * @param string $selectorName
     *
     * @return string
     */
    private function getTableAlias($selectorName)
    {
        $selectorAlias = $this->getSelectorAlias($selectorName);

        if (!isset($this->alias[$selectorAlias])) {
            $this->alias[$selectorAlias] = "n" . count($this->alias);
        }

        return $this->alias[$selectorAlias];
    }

    /**
     * @param string $selectorName
     *
     * @return string
     */
    private function getSelectorAlias($selectorName)
    {
        if (null === $selectorName) {
            if (count($this->alias)) { // We have aliases, use the first
                $selectorAlias = array_search('n0', $this->alias);
            } else { // Currently no aliases, use an empty string as index
                $selectorAlias = '';
            }
        } elseif (strpos($selectorName, ".") === false) {
            $selectorAlias = $selectorName;
        } else {
            $parts = explode(".", $selectorName);
            $selectorAlias = reset($parts);
        }

        if (strpos($selectorAlias, '[') === 0) {
            $selectorAlias = substr($selectorAlias, 1, -1);
        }

        if ($this->source && $this->source->getNodeTypeName() === $selectorAlias) {
            $selectorAlias = '';
        }

        return $selectorAlias;
    }

    /**
     * @param QueryObjectModel $qom
     *
     * @return string
     */
    public function walkQOMQuery(QueryObjectModel $qom)
    {
        $source = $qom->getSource();
        $selectors = $this->validateSource($source);

        $sourceSql = " " . $this->walkSource($source);
        $constraintSql = '';
        if ($constraint = $qom->getConstraint()) {
            $constraintSql = " AND " . $this->walkConstraint($constraint);
        }

        $orderingSql = '';
        if ($orderings = $qom->getOrderings()) {
            $orderingSql = " " . $this->walkOrderings($orderings);
        }

        $sql = "SELECT " . $this->getColumns($qom);
        $sql .= $sourceSql;
        $sql .= $constraintSql;
        $sql .= $orderingSql;

        $limit = $qom->getLimit();
        $offset = $qom->getOffset();

        if (null !== $offset && null == $limit
            && ($this->platform instanceof MySqlPlatform || $this->platform instanceof SqlitePlatform)
        ) {
            $limit = PHP_INT_MAX;
        }
        $sql = $this->platform->modifyLimitQuery($sql, $limit, $offset);

        return array($selectors, $this->alias, $sql);
    }

    /**
     * @return string
     */
    public function getColumns(QueryObjectModel $qom)
    {
        // TODO we should actually build Xpath statements for each column we actually need in the result and not fetch all 'props'
        $sqlColumns = array('path', 'identifier', 'props');

        if (count($this->alias)) {
            $aliasSql = array();
            foreach ($this->alias as $alias) {
                foreach ($sqlColumns as $sqlColumn) {
                    $aliasSql[] = sprintf('%s.%s AS %s_%s', $alias, $sqlColumn, $alias, $sqlColumn);
                }
            }
            $sql = join(', ', $aliasSql);
        } else {
            $sql = '*';
        }

        return $sql;
    }

    /**
     * Validates the nodeTypes in given source
     *
     * @param QOM\SourceInterface $source
     *
     * @return QOM\SelectorInterface[]
     *
     * @throws \PHPCR\Query\InvalidQueryException
     */
    protected function validateSource(QOM\SourceInterface $source)
    {
        if ($source instanceof QOM\SelectorInterface) {
            $selectors = array($source);
            $this->validateSelectorSource($source);
        } elseif ($source instanceof QOM\JoinInterface) {
            $selectors = $this->validateJoinSource($source);
        } else {
            $selectors = array();
        }

        return $selectors;
    }

    /**
     * @param QOM\SelectorInterface $source
     *
     * @throws \PHPCR\Query\InvalidQueryException
     */
    protected function validateSelectorSource(QOM\SelectorInterface $source)
    {
        $nodeType = $source->getNodeTypeName();

        if (!$this->nodeTypeManager->hasNodeType($nodeType)) {
            $msg = 'Selected node type does not exist: ' . $nodeType;
            if ($alias = $source->getSelectorName()) {
                $msg .= ' AS ' . $alias;
            }

            throw new InvalidQueryException($msg);
        }
    }

    /**
     * @param QOM\JoinInterface $source
     *
     * @return QOM\SelectorInterface[]
     *
     * @throws \PHPCR\Query\InvalidQueryException
     */
    protected function validateJoinSource(QOM\JoinInterface $source)
    {
        $left = $source->getLeft();
        $right = $source->getRight();

        if ($left) {
            $selectors = $this->validateSource($left);
        } else {
            $selectors = array();
        }

        if ($right) {
            // ensure that the primary selector is first
            if (QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_RIGHT_OUTER === $source->getJoinType()) {
                $selectors = array_merge($this->validateSource($right), $selectors);
            } else {
                $selectors = array_merge($selectors, $this->validateSource($right));
            }
        }

        return $selectors;
    }

    /**
     * @param QOM\SourceInterface $source
     *
     * @return string
     *
     * @throws NotImplementedException
     */
    public function walkSource(QOM\SourceInterface $source)
    {
        if ($source instanceof QOM\SelectorInterface) {
            return $this->walkSelectorSource($source);
        }

        if ($source instanceof QOM\JoinInterface) {
            return $this->walkJoinSource($source);
        }

        throw new NotImplementedException(sprintf("The source class '%s' is not supported", get_class($source)));
    }

    /**
     * @param QOM\SelectorInterface $source
     *
     * @return string
     */
    public function walkSelectorSource(QOM\SelectorInterface $source)
    {
        $this->source = $source;
        $alias = $this->getTableAlias($source->getSelectorName());
        $nodeTypeClause = $this->sqlNodeTypeClause($alias, $source);
        $sql = "FROM phpcr_nodes $alias WHERE $alias.workspace_name = ? AND $nodeTypeClause";

        return $sql;
    }

    /**
     * @param QOM\JoinConditionInterface $right
     *
     * @return string the alias on the right side of a join
     *
     * @throws \BadMethodCallException if the provided JoinCondition has no valid way of getting the right selector
     */
    private function getRightJoinSelector(QOM\JoinConditionInterface $right)
    {
        if ($right instanceof QOM\ChildNodeJoinConditionInterface) {
            return $right->getParentSelectorName();
        } elseif ($right instanceof QOM\DescendantNodeJoinConditionInterface) {
            return $right->getAncestorSelectorName();
        } elseif ($right instanceof QOM\SameNodeJoinConditionInterface || $right instanceof QOM\EquiJoinConditionInterface) {
            return $right->getSelector2Name();
        }
        throw new \BadMethodCallException('Supplied join type should implement getSelector2Name() or be an instance of ChildNodeJoinConditionInterface or DescendantNodeJoinConditionInterface');
    }

    /**
     * @param QOM\JoinInterface $source
     * @param boolean $root whether the method call is recursed for nested joins. If true, it will add a WHERE clause
     *        that checks the workspace_name and type
     *
     * @return string
     *
     * @throws NotImplementedException if the right side of the join consists of another join
     */
    public function walkJoinSource(QOM\JoinInterface $source, $root = true)
    {
        $this->source = $left = $source->getLeft(); // The $left variable is used for storing the leftmost selector

        if (!$source->getRight() instanceof QOM\SelectorInterface) {
            throw new NotImplementedException('The right side of the join should not consist of another join');
        }

        if ($source->getLeft() instanceof QOM\SelectorInterface) {
            $leftAlias = $this->getTableAlias($source->getLeft()->getSelectorName());
            $this->getTableAlias($source->getLeft()->getSelectorName());
            $sql = "FROM phpcr_nodes $leftAlias ";
        } else {
            $sql = $this->walkJoinSource($left, false) . ' '; // One step left, until we're at the selector
            $leftAlias = $this->getTableAlias($this->getRightJoinSelector($source->getLeft()->getJoinCondition()));
            while (!$left instanceof QOM\SelectorInterface) {
                $left = $left->getLeft();
            }
        }
        $rightAlias = $this->getTableAlias($source->getRight()->getSelectorName());
        $nodeTypeClause = $this->sqlNodeTypeClause($rightAlias, $source->getRight());

        switch ($source->getJoinType()) {
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_INNER:
                $sql .= "INNER JOIN phpcr_nodes $rightAlias ";
                break;
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_LEFT_OUTER:
                $sql .= "LEFT JOIN phpcr_nodes $rightAlias ";
                break;
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_RIGHT_OUTER:
                $sql .= "RIGHT JOIN phpcr_nodes $rightAlias ";
                break;
        }

        $sql .= "ON ( $leftAlias.workspace_name = $rightAlias.workspace_name AND $nodeTypeClause ";
        $sql .= "AND " . $this->walkJoinCondition($source->getLeft(), $source->getRight(), $source->getJoinCondition()) . " ";
        $sql .= ") "; // close on-clause


        if ($root) { // The method call is not recursed when $root is true, so we can add a WHERE clause
            // TODO: revise this part for alternatives
            $sql .= "WHERE $leftAlias.workspace_name = ? AND $leftAlias.type IN ('" . $left->getNodeTypeName() . "'";
            $subTypes = $this->nodeTypeManager->getSubtypes($left->getNodeTypeName());
            foreach ($subTypes as $subType) {
                /* @var $subType \PHPCR\NodeType\NodeTypeInterface */
                $sql .= ", '" . $subType->getName() . "'";
            }
            $sql .= ')';
        }

        return $sql;
    }

    /**
     * @param QOM\SelectorInterface|QOM\JoinInterface $left
     * @param QOM\SelectorInterface $right
     * @param QOM\JoinConditionInterface $condition
     *
     * @return string
     *
     * @throws NotImplementedException if a SameNodeJoinCondtion is used.
     */
    public function walkJoinCondition($left, QOM\SelectorInterface $right, QOM\JoinConditionInterface $condition)
    {
        if ($condition instanceof QOM\ChildNodeJoinConditionInterface) {
            return $this->walkChildNodeJoinCondition($condition);
        }

        if ($condition instanceof QOM\DescendantNodeJoinConditionInterface) {
            return $this->walkDescendantNodeJoinCondition($condition);
        }

        if ($condition instanceof QOM\EquiJoinConditionInterface) {
            if ($left instanceof QOM\SelectorInterface) {
                $selectorName = $left->getSelectorName();
            } else {
                $selectorName = $this->getRightJoinSelector($left->getJoinCondition());
            }
            return $this->walkEquiJoinCondition($selectorName, $right->getSelectorName(), $condition);
        }

        if ($condition instanceof QOM\SameNodeJoinConditionInterface) {
            throw new NotImplementedException("SameNodeJoinCondtion");
        }
    }

    /**
     * @param QOM\ChildNodeJoinConditionInterface $condition
     *
     * @return string
     */
    public function walkChildNodeJoinCondition(QOM\ChildNodeJoinConditionInterface $condition)
    {
        $rightAlias = $this->getTableAlias($condition->getChildSelectorName());
        $leftAlias = $this->getTableAlias($condition->getParentSelectorName());

        return "($rightAlias.path LIKE CONCAT($leftAlias.path, '/%') AND $rightAlias.depth = $leftAlias.depth + 1) ";
    }

    /**
     * @param QOM\DescendantNodeJoinConditionInterface $condition
     *
     * @return string
     */
    public function walkDescendantNodeJoinCondition(QOM\DescendantNodeJoinConditionInterface $condition)
    {
        $rightAlias = $this->getTableAlias($condition->getDescendantSelectorName());
        $leftAlias = $this->getTableAlias($condition->getAncestorSelectorName());

        return "$rightAlias.path LIKE CONCAT($leftAlias.path, '/%') ";
    }

    /**
     * @param QOM\EquiJoinConditionInterface $condition
     *
     * @return string
     */
    public function walkEquiJoinCondition($leftSelectorName, $rightSelectorName, QOM\EquiJoinConditionInterface $condition)
    {
        return $this->walkOperand(new PropertyValue($leftSelectorName, $condition->getProperty1Name())) . " " .
               $this->walkOperator(QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO) . " " .
               $this->walkOperand(new PropertyValue($rightSelectorName, $condition->getProperty2Name()));
    }

    /**
     * @param \PHPCR\Query\QOM\ConstraintInterface $constraint
     *
     * @return string
     *
     * @throws InvalidQueryException
     */
    public function walkConstraint(QOM\ConstraintInterface $constraint)
    {
        if ($constraint instanceof QOM\AndInterface) {
            return $this->walkAndConstraint($constraint);
        }
        if ($constraint instanceof QOM\OrInterface) {
            return $this->walkOrConstraint($constraint);
        }
        if ($constraint instanceof QOM\NotInterface) {
            return $this->walkNotConstraint($constraint);
        }
        if ($constraint instanceof QOM\ComparisonInterface) {
            return $this->walkComparisonConstraint($constraint);
        }
        if ($constraint instanceof QOM\DescendantNodeInterface) {
            return $this->walkDescendantNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\ChildNodeInterface) {
            return $this->walkChildNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\PropertyExistenceInterface) {
            return $this->walkPropertyExistenceConstraint($constraint);
        }
        if ($constraint instanceof QOM\SameNodeInterface) {
            return $this->walkSameNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\FullTextSearchInterface) {
            return $this->walkFullTextSearchConstraint($constraint);
        }

        throw new InvalidQueryException("Constraint " . get_class($constraint) . " not yet supported.");
    }

    /**
     * @param QOM\SameNodeInterface $constraint
     *
     * @return string
     */
    public function walkSameNodeConstraint(QOM\SameNodeInterface $constraint)
    {
        return $this->getTableAlias($constraint->getSelectorName()) . ".path = '" . $constraint->getPath() . "'";
    }

    /**
     * @param QOM\FullTextSearchInterface $constraint
     *
     * @return string
     */
    public function walkFullTextSearchConstraint(QOM\FullTextSearchInterface $constraint)
    {
        return $this->sqlXpathExtractValue($this->getTableAlias($constraint->getSelectorName()), $constraint->getPropertyName()).' LIKE '. $this->conn->quote('%'.$constraint->getFullTextSearchExpression().'%');
    }

    /**
     * @param QOM\PropertyExistenceInterface $constraint
     *
     * @return string
     */
    public function walkPropertyExistenceConstraint(QOM\PropertyExistenceInterface $constraint)
    {
        return $this->sqlXpathValueExists($this->getTableAlias($constraint->getSelectorName()), $constraint->getPropertyName());
    }

    /**
     * @param QOM\DescendantNodeInterface $constraint
     *
     * @return string
     */
    public function walkDescendantNodeConstraint(QOM\DescendantNodeInterface $constraint)
    {
        $ancestorPath = $constraint->getAncestorPath();
        if ('/' === $ancestorPath) {
            $ancestorPath = '';
        } elseif (substr($ancestorPath, -1) === '/') {
            throw new InvalidQueryException("Trailing slash in $ancestorPath");
        }

        return $this->getTableAlias($constraint->getSelectorName()) . ".path LIKE '" . $ancestorPath . "/%'";
    }

    /**
     * @param QOM\ChildNodeInterface $constraint
     *
     * @return string
     */
    public function walkChildNodeConstraint(QOM\ChildNodeInterface $constraint)
    {
        return $this->getTableAlias($constraint->getSelectorName()) . ".parent = '" . $constraint->getParentPath() . "'";
    }

    /**
     * @param QOM\AndInterface $constraint
     *
     * @return string
     */
    public function walkAndConstraint(QOM\AndInterface $constraint)
    {
        return "(" . $this->walkConstraint($constraint->getConstraint1()) . " AND " . $this->walkConstraint($constraint->getConstraint2()) . ")";
    }

    /**
     * @param QOM\OrInterface $constraint
     *
     * @return string
     */
    public function walkOrConstraint(QOM\OrInterface $constraint)
    {
        return "(" . $this->walkConstraint($constraint->getConstraint1()) . " OR " . $this->walkConstraint($constraint->getConstraint2()) . ")";
    }

    /**
     * @param QOM\NotInterface $constraint
     *
     * @return string
     */
    public function walkNotConstraint(QOM\NotInterface $constraint)
    {
        return "NOT (" . $this->walkConstraint($constraint->getConstraint()) . ")";
    }

    /**
     * This method figures out the best way to do a comparison
     * When we need to compare a property with a literal value,
     * we need to be aware of the multivalued properties, we then require
     * a different xpath statement then with other comparisons
     *
     * @param QOM\ComparisonInterface $constraint
     *
     * @return string
     */
    public function walkComparisonConstraint(QOM\ComparisonInterface $constraint)
    {
        $operator = $this->walkOperator($constraint->getOperator());

        $operator1 = $constraint->getOperand1();
        $operator2 = $constraint->getOperand2();

        // Check if we have a property and a literal value (in random order)
        if (
            ($operator1 instanceof QOM\PropertyValueInterface
                && $operator2 instanceof QOM\LiteralInterface)
            || ($operator1 instanceof QOM\LiteralInterface
                && $operator2 instanceof QOM\PropertyValueInterface)
            || ($operator1 instanceof QOM\NodeNameInterface
                && $operator2 instanceof QOM\LiteralInterface)
            || ($operator1 instanceof QOM\LiteralInterface
                && $operator2 instanceof QOM\NodeNameInterface)
        ) {
            // Check whether the left is the literal, at this point the other always is the literal/nodename operand
            if ($operator1 instanceof QOM\LiteralInterface) {
                $operand = $operator2;
                $literalOperand = $operator1;
            } else {
                $literalOperand = $operator2;
                $operand = $operator1;
            }

            if (is_string($literalOperand->getLiteralValue()) && '=' !== $operator && '!=' !== $operator) {
                return
                    $this->walkOperand($operator1) . " " .
                    $operator . " " .
                    $this->walkOperand($operator2);
            }

            if ($operand instanceof QOM\NodeNameInterface) {
                $selectorName = $operand->getSelectorName();
                $alias = $this->getTableAlias($selectorName);

                $literal = $literalOperand->getLiteralValue();
                if (false !== strpos($literal, ':')) {
                    $parts = explode(':', $literal);
                    if (!isset($this->namespaces[$parts[0]])) {
                        throw new NamespaceException('The namespace ' . $parts[0] . ' was not registered.');
                    }

                    $parts[0] = $this->namespaces[$parts[0]];
                    $literal = implode(':', $parts);
                }

                return $this->platform->getConcatExpression("$alias.namespace", "(CASE $alias.namespace WHEN '' THEN '' ELSE ':' END)", "$alias.local_name") . " " .
                    $operator . " " .
                    $this->conn->quote($literal);
            }

            if ('jcr:path' !== $operand->getPropertyName() && 'jcr:uuid' !== $operand->getPropertyName()) {
                if (is_int($literalOperand->getLiteralValue()) || is_float($literalOperand->getLiteralValue())) {
                    return $this->walkNumComparisonConstraint($operand, $literalOperand, $operator);
                }
                if (is_bool($literalOperand->getLiteralValue())) {
                    return $this->walkBoolComparisonConstraint($operand, $literalOperand, $operator);
                }

                return $this->walkTextComparisonConstraint($operand, $literalOperand, $operator);
            }
        }

        return
            $this->walkOperand($operator1) . " " .
            $operator . " " .
            $this->walkOperand($operator2);
    }

    /**
     * @param QOM\PropertyValueInterface $propertyOperand
     * @param QOM\LiteralInterface $literalOperand
     * @param string $operator
     *
     * @return string
     */
    public function walkTextComparisonConstraint(QOM\PropertyValueInterface $propertyOperand, QOM\LiteralInterface $literalOperand, $operator)
    {
        $alias = $this->getTableAlias($propertyOperand->getSelectorName() . '.' . $propertyOperand->getPropertyName());
        $property = $propertyOperand->getPropertyName();

        return $this->sqlXpathComparePropertyValue($alias, $property, $this->getLiteralValue($literalOperand), $operator);
    }

    public function walkBoolComparisonConstraint(QOM\PropertyValueInterface $propertyOperand, QOM\LiteralInterface $literalOperand, $operator)
    {
        $value = true === $literalOperand->getLiteralValue() ? '1' : '0';

        return $this->walkOperand($propertyOperand) . " " . $operator . " " . $this->conn->quote($value);
    }

    public function walkNumComparisonConstraint(QOM\PropertyValueInterface $propertyOperand, QOM\LiteralInterface $literalOperand, $operator)
    {
        $alias = $this->getTableAlias($propertyOperand->getSelectorName() . '.' . $propertyOperand->getPropertyName());
        $property = $propertyOperand->getPropertyName();


        if ($this->platform instanceof MySqlPlatform && '=' === $operator) {
            return sprintf(
                '0 != FIND_IN_SET("%s", REPLACE(EXTRACTVALUE(%s.props, \'//sv:property[@sv:name="%s"]/sv:value\'), " ", ","))',
                $literalOperand->getLiteralValue(),
                $alias,
                $property
            );
        }

        return sprintf(
            '%s %s %s',
            $this->sqlXpathExtractNumValue($alias, $property),
            $operator,
            $literalOperand->getLiteralValue()
        );
    }

    /**
     * @param string $operator
     *
     * @return string
     */
    public function walkOperator($operator)
    {
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO) {
            return "=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN) {
            return ">";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN_OR_EQUAL_TO) {
            return ">=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN) {
            return "<";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN_OR_EQUAL_TO) {
            return "<=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_NOT_EQUAL_TO) {
            return "!=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LIKE) {
            return "LIKE";
        }

        return $operator; // no-op for simplicity, not standard conform (but using the constants is a pain)
    }

    /**
     * @param QOM\OperandInterface $operand
     *
     * @return string
     *
     * @throws InvalidQueryException
     */
    public function walkOperand(QOM\OperandInterface $operand)
    {
        if ($operand instanceof QOM\NodeNameInterface) {
            $selectorName = $operand->getSelectorName();
            $alias = $this->getTableAlias($selectorName);

            return $this->platform->getConcatExpression("$alias.namespace", "(CASE $alias.namespace WHEN '' THEN '' ELSE ':' END)", "$alias.local_name");
        }
        if ($operand instanceof QOM\NodeLocalNameInterface) {
            $selectorName = $operand->getSelectorName();
            $alias = $this->getTableAlias($selectorName);

            return "$alias.local_name";
        }
        if ($operand instanceof QOM\LowerCaseInterface) {
            return $this->platform->getLowerExpression($this->walkOperand($operand->getOperand()));
        }
        if ($operand instanceof QOM\UpperCaseInterface) {
            return $this->platform->getUpperExpression($this->walkOperand($operand->getOperand()));
        }
        if ($operand instanceof QOM\LiteralInterface) {
            return $this->conn->quote($this->getLiteralValue($operand));
        }
        if ($operand instanceof QOM\PropertyValueInterface) {
            $alias = $this->getTableAlias($operand->getSelectorName() . '.' . $operand->getPropertyName());
            $property = $operand->getPropertyName();
            if ($property == "jcr:path") {
                return $alias . ".path";
            }
            if ($property == "jcr:uuid") {
                return $alias . ".identifier";
            }

            return $this->sqlXpathExtractValue($alias, $property);
        }
        if ($operand instanceof QOM\LengthInterface) {
            $alias = $this->getTableAlias($operand->getPropertyValue()->getSelectorName());
            $property = $operand->getPropertyValue()->getPropertyName();

            return $this->sqlXpathExtractValueAttribute($alias, $property, 'length');
        }

        throw new InvalidQueryException("Dynamic operand " . get_class($operand) . " not yet supported.");
    }

    /**
     * @param array $orderings
     *
     * @return string
     */
    public function walkOrderings(array $orderings)
    {
        $sql = '';
        foreach ($orderings as $ordering) {
            $sql .= empty($sql) ? "ORDER BY " : ", ";
            $sql .= $this->walkOrdering($ordering);
        }

        return $sql;
    }

    /**
     * @param QOM\OrderingInterface $ordering
     *
     * @return string
     */
    public function walkOrdering(QOM\OrderingInterface $ordering)
    {
        $direction = $ordering->getOrder();
        if ($direction === QOM\QueryObjectModelConstantsInterface::JCR_ORDER_ASCENDING) {
            $direction = 'ASC';
        } elseif ($direction === QOM\QueryObjectModelConstantsInterface::JCR_ORDER_DESCENDING) {
            $direction = 'DESC';
        }

        $sql = $this->walkOperand($ordering->getOperand());

        if ($ordering->getOperand() instanceof QOM\PropertyValueInterface) {
            $operand = $ordering->getOperand();
            $property = $ordering->getOperand()->getPropertyName();
            if ($property !== 'jcr:path' && $property !== 'jcr:uuid') {
                $alias = $this->getTableAlias($operand->getSelectorName() . '.' . $property);

                $numericalSelector = $this->sqlXpathExtractValue($alias, $property, 'numerical_props');

                $sql = sprintf('CAST(%s AS DECIMAL) %s, %s',
                    $numericalSelector,
                    $direction,
                    $sql
                );
            }
        }

        $sql .= ' ' .$direction;

        return $sql;
    }

    /**
     * @param QOM\LiteralInterface $operand
     *
     * @return string
     *
     * @throws NamespaceException
     */
    private function getLiteralValue(QOM\LiteralInterface $operand)
    {
        $value = $operand->getLiteralValue();

        return $value instanceof \DateTime ? $value->format('c') : $value;
    }

    /**
     * SQL to execute an XPATH expression checking if the property exist on the node with the given alias.
     *
     * @param string $alias
     * @param string $property
     *
     * @return string
     */
    private function sqlXpathValueExists($alias, $property)
    {
        if ($this->platform instanceof MySqlPlatform) {
            return "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1])') = 1";
        }
        if ($this->platform instanceof PostgreSqlPlatform) {
            return "xpath_exists('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]', CAST($alias.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces().") = 't'";
        }
        if ($this->platform instanceof SqlitePlatform) {
            return "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1])') = 1";
        }

        throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
    }

    /**
     * SQL to execute an XPATH expression extracting the property value on the node with the given alias.
     *
     * @param string $alias
     * @param string $property
     *
     * @return string
     */
    private function sqlXpathExtractValue($alias, $property, $column = 'props')
    {
        if ($this->platform instanceof MySqlPlatform) {
            return "EXTRACTVALUE($alias.$column, '//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]')";
        }
        if ($this->platform instanceof PostgreSqlPlatform) {
            return "(xpath('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]/text()', CAST($alias.$column AS xml), ".$this->sqlXpathPostgreSQLNamespaces()."))[1]::text";
        }
        if ($this->platform instanceof SqlitePlatform) {
            return "EXTRACTVALUE($alias.$column, '//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]')";
        }

        throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
    }

    private function sqlXpathExtractNumValue($alias, $property)
    {
        if ($this->platform instanceof PostgreSqlPlatform) {
            return "(xpath('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]/text()', CAST($alias.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces()."))[1]::text::int";
        }


        return 'CAST(' . $this->sqlXpathExtractValue($alias, $property) . ' AS DECIMAL)';
    }

    private function sqlXpathExtractValueAttribute($alias, $property, $attribute, $valueIndex = 1)
    {
        if ($this->platform instanceof MySqlPlatform) {
            return sprintf("EXTRACTVALUE(%s.props, '//sv:property[@sv:name=\"%s\"]/sv:value[%d]/@%s')", $alias, $property, $valueIndex, $attribute);
        }
        if ($this->platform instanceof PostgreSqlPlatform) {
            return sprintf("CAST((xpath('//sv:property[@sv:name=\"%s\"]/sv:value[%d]/@%s', CAST(%s.props AS xml), %s))[1]::text AS bigint)", $property, $valueIndex, $attribute, $alias, $this->sqlXpathPostgreSQLNamespaces());
        }
        if ($this->platform instanceof SqlitePlatform) {
            return sprintf("EXTRACTVALUE(%s.props, '//sv:property[@sv:name=\"%s\"]/sv:value[%d]/@%s')", $alias, $property, $valueIndex, $attribute);
        }

        throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
    }

    /**
     * @param $alias
     * @param $property
     * @param $value
     * @param string $operator
     *
     * @return string
     *
     * @throws NotImplementedException if the storage backend is neither mysql
     *      nor postgres nor sqlite
     */
    private function sqlXpathComparePropertyValue($alias, $property, $value, $operator)
    {
        $expression = null;

        if ($this->platform instanceof MySqlPlatform) {
            $expression = "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[text()%s%s]) > 0')";
            // mysql does not escape the backslashes for us, while postgres and sqlite do
            $value = Xpath::escapeBackslashes($value);
        } elseif ($this->platform instanceof PostgreSqlPlatform) {
            $expression = "xpath_exists('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[text()%s%s]', CAST($alias.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces().") = 't'";
        } elseif ($this->platform instanceof SqlitePlatform) {
            $expression = "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[text()%s%s]) > 0')";
        } else {
            throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
        }

        return sprintf($expression, $this->walkOperator($operator), Xpath::escape($value));
    }

    /**
     * @return string
     */
    private function sqlXpathPostgreSQLNamespaces()
    {
        return "ARRAY[ARRAY['sv', 'http://www.jcp.org/jcr/sv/1.0']]";
    }

    /**
     * @param QOM\SelectorInterface $source
     * @param string                $alias
     *
     * @return string
     */
    private function sqlNodeTypeClause($alias, QOM\SelectorInterface $source)
    {
        $sql = "$alias.type IN ('" . $source->getNodeTypeName() ."'";

        $subTypes = $this->nodeTypeManager->getSubtypes($source->getNodeTypeName());
        foreach ($subTypes as $subType) {
            /* @var $subType \PHPCR\NodeType\NodeTypeInterface */
            $sql .= ", '" . $subType->getName() . "'";
        }
        $sql .= ')';

        return $sql;
    }
}
