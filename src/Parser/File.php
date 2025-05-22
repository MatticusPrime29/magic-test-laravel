<?php

namespace MagicTest\MagicTest\Parser;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MagicTest\MagicTest\Exceptions\InvalidFileException;
use MagicTest\MagicTest\MagicTest;
use MagicTest\MagicTest\Parser\Printer\PrettyPrinter;
use MagicTest\MagicTest\Parser\Visitors\GrammarBuilderVisitor;
use MagicTest\MagicTest\Parser\Visitors\MagicRemoverVisitor;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_ as AstString;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class File
{
    protected Parser $parser;

    protected Lexer $lexer;

    protected array $ast;

    protected array $initialStatements;

    protected array $newStatements;

    protected ?Closure $closure;

    public function __construct(string $content, string $method)
    {
        $this->lexer = new \PhpParser\Lexer\Emulative();

        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $parsedNodes = $this->parser->parse($content);
        if (empty($parsedNodes)) {
            throw new Exception('bruh');
        }
        $this->ast = $parsedNodes;
        $this->initialStatements = $this->ast;

        $this->newStatements = $this->getNewStatements();
        $this->closure = $this->getClosure($method);
    }

    public static function fromContent(string $content, string $method)
    {
        return new static($content, $method);
    }

    public function addMethods(Collection $grammar): string
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $traverser->addVisitor(new GrammarBuilderVisitor($grammar));
        $traverser->traverse($this->closure->stmts);

        return $this->print();
    }

    public function finish(): string
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $traverser->addVisitor(new MagicRemoverVisitor);
        $traverser->traverse($this->closure->stmts);

        return $this->print();
    }

    protected function print(): string
    {
        return (new PrettyPrinter)->printFormatPreserving(
            $this->newStatements,
            $this->initialStatements,
            $this->parser->getTokens(),
        );
    }

    /**
     * Clone the statements to leave the starting ones untouched so they can be diffed by the printer later.
     *
     * @return array
     */
    protected function getNewStatements(): array
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new CloningVisitor);

        return $traverser->traverse($this->initialStatements);
    }

    protected function getClassMethod(string $methodName): ?ClassMethod
    {
        $classNode = $this->getClassNode($this->newStatements);

        if (!$classNode) {
            Log::error('Could not find class node');
            return null;
        }

        return (new NodeFinder)->findFirst(
            $classNode->stmts,
            fn(Node $node) => $node instanceof ClassMethod &&
                $node->name instanceof Identifier &&
                $node->name->toString() === $methodName
        );
    }

    protected function getClassNode(array $nodesToSearch): ?Class_
    {
        $nodeFinder = new NodeFinder;
        $classNode = $nodeFinder->findFirstInstanceOf($nodesToSearch, Class_::class);

        if (!$classNode) {
            $namespaceNode = $nodeFinder->findFirstInstanceOf($nodesToSearch, Namespace_::class);
            if ($namespaceNode && $namespaceNode->stmts) {
                $classNode = $nodeFinder->findFirstInstanceOf($namespaceNode->stmts, Class_::class);
            }
        }
        return $classNode;
    }


    /**
     * Finds the first valid method call inside a class method.
     * A valid method call is one that is both a MethodCall instance and
     * that also has a node that is a Identifier and ahs the name magic.
     *
     * @param \PhpParser\Node\Stmt\ClassMethod $classMethod
     * @return \PhpParser\Node\Expr\MethodCall|null
     */
    protected function getMethodCall(ClassMethod $classMethod): ?MethodCall
    {
        return (new NodeFinder)->findFirst($classMethod->stmts, function (Node $node) {
            return $node instanceof MethodCall &&
                (new NodeFinder)->find(
                    $node->args,
                    fn(Node $node) => $node instanceof Identifier && $node->name === 'magic'
                );
        });
    }

    /**
     * Get the closure object
     *
     * @param string $method
     * @return Closure
     * @throws \MagicTest\MagicTest\Exceptions\InvalidFileException
     */

    protected function getClosure(string $methodOrTestName): ?Closure
    {
        $targetBrowseClosure = null;

        // Attempt 1: Class-based test
        $classMethod = $this->getClassMethod($methodOrTestName);

        if ($classMethod) {
            Log::info("[MagicTest] Found class method: {$methodOrTestName}. Searching for browse() closure.");
            $browseMethodCallNode = (new NodeFinder)->findFirst($classMethod->stmts, function (Node $node) {
                return $node instanceof Expression &&
                    $node->expr instanceof MethodCall &&
                    $node->expr->var instanceof Variable &&
                    $node->expr->var->name === 'this' &&
                    $node->expr->name instanceof Identifier &&
                    $node->expr->name->toString() === 'browse';
            });

            if ($browseMethodCallNode instanceof Expression &&
                $browseMethodCallNode->expr instanceof MethodCall &&
                isset($browseMethodCallNode->expr->getArgs()[0]) &&
                $browseMethodCallNode->expr->getArgs()[0]->value instanceof Closure) {
                $targetBrowseClosure = $browseMethodCallNode->expr->getArgs()[0]->value;
            } else {
                Log::warning("[MagicTest] Could not find \$this->browse(Closure) in class method: {$methodOrTestName}");
            }
        } else {
            Log::info("[MagicTest] Class method '{$methodOrTestName}' not found. Attempting to find Pest test.");

            $pestTestOuterClosure = $this->getPestTestOuterClosure($methodOrTestName, $this->newStatements);

            if ($pestTestOuterClosure) {
                Log::info("[MagicTest] Found Pest test: {$methodOrTestName}. Searching for browse() closure within it.");
                $browseMethodCallNode = (new NodeFinder)->findFirst($pestTestOuterClosure->stmts, function (Node $node) {

                    $isMatch = $node instanceof Expression &&
                        $node->expr instanceof MethodCall &&
                        $node->expr->var instanceof Variable &&
                        $node->expr->var->name === 'this' &&
                        $node->expr->name instanceof Identifier &&
                        $node->expr->name->toString() === 'browse';

                    if ($isMatch) {
                        Log::debug('[MagicTest NodeDebug] Match found for $this->browse()');
                    }

                    return $isMatch;
                });

                if ($browseMethodCallNode instanceof Expression &&
                    $browseMethodCallNode->expr instanceof MethodCall &&
                    isset($browseMethodCallNode->expr->getArgs()[0]) &&
                    $browseMethodCallNode->expr->getArgs()[0]->value instanceof Closure) {
                    $targetBrowseClosure = $browseMethodCallNode->expr->getArgs()[0]->value;
                } else {
                    Log::warning("[MagicTest] Could not find \$this->browse(Closure) in Pest test: {$methodOrTestName}");
                }
            }
        }

        if (!$targetBrowseClosure) {
            $filePath = property_exists(MagicTest::class, 'file') && MagicTest::$file ? MagicTest::$file : 'unknown';
            throw new InvalidFileException(
                "Could not find a valid \$this->browse(Closure) in method or Pest test '{$methodOrTestName}'. File: " . $filePath
            );
        }

        return $targetBrowseClosure;
    }

    protected function getPestTestOuterClosure(string $testName, array $nodesToSearch): ?Closure
    {
        $nodeFinder = new NodeFinder;

        $testFunctionCallExpression = $nodeFinder->findFirst($nodesToSearch, function (Node $node) use ($testName) {
            if (!($node instanceof Expression && $node->expr instanceof FuncCall)) {
                return false;
            }

            $funcCall = $node->expr;

            if (!($funcCall->name instanceof Name && $funcCall->name->toString() === 'test')) {
                return false;
            }

            if (count($funcCall->getArgs()) < 2 || !($funcCall->getArgs()[1]->value instanceof Closure)) {
                return false;
            }

            if ($testName === '{closure}') {
                return true;
            }


            $firstArgValue = $funcCall->getArgs()[0]->value;
            return $firstArgValue instanceof AstString && $firstArgValue->value === $testName;
        });

        if ($testFunctionCallExpression instanceof Expression &&
            $testFunctionCallExpression->expr instanceof FuncCall &&
            isset($testFunctionCallExpression->expr->getArgs()[1]) &&
            $testFunctionCallExpression->expr->getArgs()[1]->value instanceof Closure
        ) {
            return $testFunctionCallExpression->expr->getArgs()[1]->value;
        }

        return null;
    }

}
