<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CodeMessDetector\Rule\UnusedCode;

use PDepend\Source\AST\ASTFormalParameter;
use PHPMD\AbstractNode;
use PHPMD\Node\ASTNode;
use PHPMD\Node\ClassNode;
use PHPMD\Node\MethodNode;
use PHPMD\Rule\AbstractLocalVariable;
use PHPMD\Rule\FunctionAware;
use PHPMD\Rule\MethodAware;

/**
 * UnusedFormalParameter rule compatible with both PHPMD 2.x and PHPMD 3.x (where PHPMD\Rule\UnusedFormalParameter is final).
 */
class UnusedFormalParameter extends AbstractLocalVariable implements FunctionAware, MethodAware
{
    /**
     * Collected AST nodes.
     *
     * @var array<string, ASTNode>
     */
    protected $nodes = [];

    /**
     * Check that all parameters of a given function or method are used at least once.
     *
     * @param AbstractNode $node
     * @return void
     */
    public function apply(AbstractNode $node): void
    {
        if ($this->isAbstractMethod($node)
            || $this->isMagicMethod($node)
            || $this->isInheritedSignature($node)
            || $this->isNotDeclaration($node)
        ) {
            return;
        }

        $this->nodes = [];
        $this->collectParameters($node);
        $this->removeUsedParameters($node);

        foreach ($this->nodes as $unusedNode) {
            $this->addViolation($unusedNode, [$unusedNode->getImage()]);
        }
    }

    /**
     * @param AbstractNode $node
     * @return bool
     */
    protected function isAbstractMethod(AbstractNode $node): bool
    {
        return $node instanceof MethodNode && $node->isAbstract();
    }

    /**
     * @param AbstractNode $node
     * @return bool
     */
    protected function isInheritedSignature(AbstractNode $node): bool
    {
        if ($node instanceof MethodNode) {
            $comment = $node->getDocComment();

            return (bool) ($comment && preg_match('/@inheritdoc/i', $comment));
        }

        return false;
    }

    /**
     * @param AbstractNode $node
     * @return bool
     */
    protected function isMagicMethod(AbstractNode $node): bool
    {
        if (!($node instanceof MethodNode)) {
            return false;
        }

        return preg_match('/__(?:call|callStatic|get|set|isset|unset|set_state)/i', $node->getName()) === 1;
    }

    /**
     * @param AbstractNode $node
     * @return bool
     */
    protected function isNotDeclaration(AbstractNode $node): bool
    {
        return $node instanceof MethodNode && !$node->isDeclaration();
    }

    /**
     * @param AbstractNode $node
     * @return void
     */
    protected function collectParameters(AbstractNode $node): void
    {
        foreach ($node->findChildrenOfType('FormalParameters') as $parameters) {
            foreach ($parameters->findChildrenOfType('VariableDeclarator') as $declarator) {
                $this->nodes[$declarator->getImage()] = $declarator;
            }
        }
    }

    /**
     * @param AbstractNode $node
     * @return void
     */
    protected function removeUsedParameters(AbstractNode $node): void
    {
        $this->removeRegularVariables($node);
        $this->removeCompoundVariables($node);
        $this->removeVariablesUsedByFuncGetArgs($node);
        $this->removePropertyPromotionVariables($node);
        $this->removeVariablesUsedInPlugins($node);
    }

    /**
     * @param AbstractNode $node
     * @return void
     */
    protected function removeRegularVariables(AbstractNode $node): void
    {
        foreach ($node->findChildrenOfTypeVariable() as $variable) {
            if ($this->isRegularVariable($variable)) {
                unset($this->nodes[$variable->getImage()]);
            }
        }
    }

    /**
     * @param AbstractNode $node
     * @return void
     */
    protected function removeCompoundVariables(AbstractNode $node): void
    {
        foreach ($node->findChildrenOfType('CompoundVariable') as $compoundVariable) {
            $variablePrefix = $compoundVariable->getImage();
            foreach ($compoundVariable->findChildrenOfType('Expression') as $child) {
                $variableImage = $variablePrefix . $child->getImage();
                unset($this->nodes[$variableImage]);
            }
        }
    }

    /**
     * @param AbstractNode $node
     * @return void
     */
    protected function removeVariablesUsedByFuncGetArgs(AbstractNode $node): void
    {
        foreach ($node->findChildrenOfType('FunctionPostfix') as $functionCall) {
            if ($this->isFunctionNameEqual($functionCall, 'func_get_args')) {
                $this->nodes = [];
            }
            if ($this->isFunctionNameEndingWith($functionCall, 'compact')) {
                foreach ($functionCall->findChildrenOfType('Literal') as $literal) {
                    unset($this->nodes['$' . trim($literal->getImage(), '"\'')]);
                }
            }
        }
    }

    /**
     * @param AbstractNode $node
     * @return void
     */
    protected function removePropertyPromotionVariables(AbstractNode $node): void
    {
        if (!$node instanceof MethodNode || $node->getImage() !== '__construct') {
            return;
        }

        /** @var ASTFormalParameter&ASTNode $parameter */
        foreach ($node->findChildrenOfType('FormalParameter') as $parameter) {
            if ($parameter->isPromoted()) {
                $variable = $parameter->getFirstChildOfType('VariableDeclarator');
                if ($variable !== null) {
                    unset($this->nodes[$variable->getImage()]);
                }
            }
        }
    }

    /**
     * @param AbstractNode $node
     * @return void
     */
    private function removeVariablesUsedInPlugins(AbstractNode $node): void
    {
        if (!$node instanceof MethodNode) {
            return;
        }

        /** @var ClassNode $classNode */
        $classNode = $node->getParentType();
        if (stripos($classNode->getNamespaceName(), 'plugin') === false) {
            return;
        }

        foreach (['around', 'after'] as $pluginMethodPrefix) {
            if (strpos($node->getImage(), $pluginMethodPrefix) === 0) {
                array_splice($this->nodes, 0, 2);
                break;
            }
        }

        if (strpos($node->getImage(), 'before') === 0) {
            array_splice($this->nodes, 0, 1);
        }
    }
}
