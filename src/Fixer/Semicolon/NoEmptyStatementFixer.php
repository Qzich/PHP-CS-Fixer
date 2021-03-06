<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\Semicolon;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;

/**
 * @author SpacePossum
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
final class NoEmptyStatementFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isTokenKindFound(';');
    }

    /**
     * {@inheritdoc}
     */
    public function fix(\SplFileInfo $file, Tokens $tokens)
    {
        for ($index = 0, $count = $tokens->count(); $index < $count; ++$index) {
            // skip T_FOR parenthesis to ignore duplicated `;` like `for ($i = 1; ; ++$i) {...}`
            if ($tokens[$index]->isGivenKind(T_FOR)) {
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $tokens->getNextMeaningfulToken($index)) + 1;
                continue;
            }

            if (!$tokens[$index]->equals(';')) {
                continue;
            }

            $previousMeaningfulIndex = $tokens->getPrevMeaningfulToken($index);

            // A semicolon can always be removed if it follows a semicolon, '{' or opening tag.
            if ($tokens[$previousMeaningfulIndex]->equalsAny(array('{', ';', array(T_OPEN_TAG)))) {
                $tokens->clearTokenAndMergeSurroundingWhitespace($index);
                continue;
            }

            // A semicolon might be removed if it follows a '}' but only if the brace is part of certain structures.
            if ($tokens[$previousMeaningfulIndex]->equals('}')) {
                $this->fixSemicolonAfterCurlyBraceClose($tokens, $index, $previousMeaningfulIndex);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // should be run before the BracesFixer, CombineConsecutiveUnsetsFixer, NoExtraConsecutiveBlankLinesFixer, NoMultilineWhitespaceBeforeSemicolonsFixer, NoSinglelineWhitespaceBeforeSemicolonsFixer,
        // NoTrailingCommaInListCallFixer, NoUselessReturnFixer, NoWhitespaceInBlankLineFixer, SpaceAfterSemicolonFixer, SwitchCaseSemicolonToColonFixer.
        return 26;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDescription()
    {
        return 'Remove useless semicolon statements.';
    }

    /**
     * Fix semicolon after closing curly brace if needed.
     *
     * Test for the following cases
     * - just '{' '}' block (following open tag or ';')
     * - if, else, elseif
     * - interface, trait, class (but not anonymous)
     * - catch, finally (but not try)
     * - for, foreach, while (but not 'do - while')
     * - switch
     * - function (declaration, but not lambda)
     * - declare (with '{' '}')
     * - namespace (with '{' '}')
     *
     * @param Tokens $tokens
     * @param int    $index           Semicolon index
     * @param int    $curlyCloseIndex
     */
    private function fixSemicolonAfterCurlyBraceClose(Tokens $tokens, $index, $curlyCloseIndex)
    {
        static $beforeCurlyOpeningKinds = null;
        if (null === $beforeCurlyOpeningKinds) {
            $beforeCurlyOpeningKinds = array(T_ELSE, T_NAMESPACE, T_OPEN_TAG);
            if (defined('T_FINALLY')) {
                $beforeCurlyOpeningKinds[] = T_FINALLY;
            }
        }

        $curlyOpeningIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $curlyCloseIndex, false);
        $beforeCurlyOpening = $tokens->getPrevMeaningfulToken($curlyOpeningIndex);
        if ($tokens[$beforeCurlyOpening]->isGivenKind($beforeCurlyOpeningKinds) || $tokens[$beforeCurlyOpening]->equalsAny(array(';', '{', '}'))) {
            $tokens->clearTokenAndMergeSurroundingWhitespace($index);

            return;
        }

        // check for namespaces and class, interface and trait definitions
        if ($tokens[$beforeCurlyOpening]->isGivenKind(T_STRING)) {
            $classyTest = $tokens->getPrevMeaningfulToken($beforeCurlyOpening);
            while ($tokens[$classyTest]->equals(',') || $tokens[$classyTest]->isGivenKind(array(T_STRING, T_NS_SEPARATOR, T_EXTENDS, T_IMPLEMENTS))) {
                $classyTest = $tokens->getPrevMeaningfulToken($classyTest);
            }

            $tokensAnalyzer = new TokensAnalyzer($tokens);

            if (
                $tokens[$classyTest]->isGivenKind(T_NAMESPACE) ||
                ($tokens[$classyTest]->isClassy() && !$tokensAnalyzer->isAnonymousClass($classyTest))
            ) {
                $tokens->clearTokenAndMergeSurroundingWhitespace($index);
            }

            return;
        }

        // early return check, below only control structures with conditions are fixed
        if (!$tokens[$beforeCurlyOpening]->equals(')')) {
            return;
        }

        $openingBrace = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $beforeCurlyOpening, false);
        $beforeOpeningBrace = $tokens->getPrevMeaningfulToken($openingBrace);

        if ($tokens[$beforeOpeningBrace]->isGivenKind(array(T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH, T_CATCH, T_DECLARE))) {
            $tokens->clearTokenAndMergeSurroundingWhitespace($index);

            return;
        }

        // check for function definition
        if ($tokens[$beforeOpeningBrace]->isGivenKind(T_STRING)) {
            $beforeString = $tokens->getPrevMeaningfulToken($beforeOpeningBrace);
            if ($tokens[$beforeString]->isGivenKind(T_FUNCTION)) {
                $tokens->clearTokenAndMergeSurroundingWhitespace($index); // implicit return
            }
        }
    }
}
