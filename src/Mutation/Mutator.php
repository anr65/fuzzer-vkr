<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

/*
 * Mutations based on https://github.com/llvm/llvm-project/blob/master/compiler-rt/lib/fuzzer/FuzzerMutate.cpp.
 */
final class Mutator {
    private RNG $rng;
    private Dictionary $dictionary;
    /** @var list<callable> */
    private array $mutators;
    private ?string $crossOverWith = null; // TODO: Get rid of this
    private ?string $forcedMutatorName = null;
    private ?int $forcedOffset = null;
    private ?int $forcedLength = null;
    private ?string $lastMutatorUsed = null;
    private TypeAwareMutator $typeAwareMutator;
    private ?StructuralCrossOver $structuralCrossOver = null;


    public function __construct(RNG $rng, Dictionary $dictionary, ?array $mutatorProfile = null) {
        $this->rng = $rng;
        $this->dictionary = $dictionary;
        $this->typeAwareMutator = new TypeAwareMutator();
        
        // Build full mutator map
        $allMutators = [
            'EraseBytes' => [$this, 'mutateEraseBytes'],
            'InsertByte' => [$this, 'mutateInsertByte'],
            'InsertRepeatedBytes' => [$this, 'mutateInsertRepeatedBytes'],
            'ChangeByte' => [$this, 'mutateChangeByte'],
            'ChangeBit' => [$this, 'mutateChangeBit'],
            'ShuffleBytes' => [$this, 'mutateShuffleBytes'],
            'ChangeASCIIInt' => [$this, 'mutateChangeASCIIInt'],
            'ChangeBinInt' => [$this, 'mutateChangeBinInt'],
            'CopyPart' => [$this, 'mutateCopyPart'],
            'CrossOver' => [$this, 'mutateCrossOver'],
            'AddWordFromManualDictionary' => [$this, 'mutateAddWordFromManualDictionary'],
            'TypeAwareMutator' => [$this, 'mutateTypeAware'],
        ];
        
        // If profile is provided, filter mutators; otherwise use all
        if ($mutatorProfile !== null && !empty($mutatorProfile)) {
            $this->mutators = [];
            foreach ($mutatorProfile as $mutatorName) {
                if (isset($allMutators[$mutatorName])) {
                    $this->mutators[] = $allMutators[$mutatorName];
                }
            }
            // If profile resulted in empty mutators, fall back to all
            if (empty($this->mutators)) {
                $this->mutators = array_values($allMutators);
            }
        } else {
            // Default: use all mutators
            $this->mutators = array_values($allMutators);
        }
    }

    /**
     * @return list<callable>
     */
    public function getMutators(): array {
        return $this->mutators;
    }

    /**
     * @return list<string>
     */
    public function getMutatorNames(): array {
        $names = [];
        foreach ($this->mutators as $mutator) {
            $names[] = $mutator[1] === 'mutateTypeAware'
                ? 'TypeAwareMutator'
                : substr((string) $mutator[1], 6);
        }
        return $names;
    }

    public function setStructuralCrossOver(?StructuralCrossOver $structuralCrossOver): void {
        $this->structuralCrossOver = $structuralCrossOver;
    }

    public function getLastMutatorUsed(): ?string {
        return $this->lastMutatorUsed;
    }

    public function setForcedSelection(?string $mutatorName, ?int $offset, ?int $length): void {
        $this->forcedMutatorName = $mutatorName;
        $this->forcedOffset = $offset;
        $this->forcedLength = $length;
    }

    private function randomBiasedChar(): string {
        if ($this->rng->randomBool()) {
            return $this->rng->randomChar();
        }
        $chars = "!*'();:@&=+$,/?%#[]012Az-`~.\xff\x00";
        return $chars[$this->rng->randomPos($chars)];
    }

    public function mutateEraseBytes(string $str, int $maxLen): ?string {
        $len = \strlen($str);
        if ($len <= 1) {
            return null;
        }

        $minNumBytes = $maxLen < $len ? $len - $maxLen : 0;
        $maxNumBytes = min($minNumBytes + ($len >> 1), $len);
        $numBytes = $this->rng->randomIntRange($minNumBytes, $maxNumBytes);
        $pos = $this->rng->randomInt($len - $numBytes + 1);
        return \substr($str, 0, $pos)
            . \substr($str, $pos + $numBytes);
    }

    public function mutateInsertByte(string $str, int $maxLen): ?string {
        if (\strlen($str) >= $maxLen) {
            return null;
        }

        $pos = $this->rng->randomPosOrEnd($str);
        return \substr($str, 0, $pos)
            . $this->randomBiasedChar()
            . \substr($str, $pos);
    }

    public function mutateInsertRepeatedBytes(string $str, int $maxLen): ?string {
        $minNumBytes = 3;
        $len = \strlen($str);
        if ($len + $minNumBytes >= $maxLen) {
            return null;
        }

        $maxNumBytes = min($maxLen - $len, 128);
        $numBytes = $this->rng->randomIntRange($minNumBytes, $maxNumBytes);
        $pos = $this->rng->randomPosOrEnd($str);
        // TODO: Biasing?
        $char = $this->rng->randomChar();
        return \substr($str, 0, $pos)
            . str_repeat($char, $numBytes)
            . \substr($str, $pos);
    }

    public function mutateChangeByte(string $str, int $maxLen): ?string {
        if ($str === '' || \strlen($str) > $maxLen) {
            return null;
        }

        $pos = $this->rng->randomPos($str);
        $str[$pos] = $this->randomBiasedChar();
        return $str;
    }

    public function mutateChangeBit(string $str, int $maxLen): ?string {
        if ($str === '' || \strlen($str) > $maxLen) {
            return null;
        }

        $pos = $this->rng->randomPos($str);
        $bit = 1 << $this->rng->randomInt(8);
        $str[$pos] = \chr(\ord($str[$pos]) ^ $bit);
        return $str;
    }

    public function mutateShuffleBytes(string $str, int $maxLen): ?string {
        $len = \strlen($str);
        if ($str === '' || $len > $maxLen) {
            return null;
        }
        $numBytes = $this->rng->randomInt(min($len, 8)) + 1;
        $pos = $this->rng->randomInt($len - $numBytes + 1);
        // TODO: This does not use the RNG!
        return \substr($str, 0, $pos)
            . \str_shuffle(\substr($str, $pos, $numBytes))
            . \substr($str, $pos + $numBytes);

    }

    public function mutateChangeASCIIInt(string $str, int $maxLen): ?string {
        $len = \strlen($str);
        if ($str === '' || $len > $maxLen) {
            return null;
        }

        $beginPos = $this->rng->randomPos($str);
        while ($beginPos < $len && !\ctype_digit($str[$beginPos])) {
            $beginPos++;
        }
        if ($beginPos === $len) {
            return null;
        }
        $endPos = $beginPos;
        while ($endPos < $len && \ctype_digit($str[$endPos])) {
            $endPos++;
        }
        // TODO: We won't be able to get large unsigned integers here.
        $int = (int) \substr($str, $beginPos, $endPos - $beginPos);
        switch ($this->rng->randomInt(4)) {
            case 0:
                $int++;
                break;
            case 1:
                $int--;
                break;
            case 2:
                $int >>= 1;
                break;
            case 3:
                $int <<= 1;
                break;
            default:
                throw new \Error("Cannot happen");
        }

        $intStr = (string) $int;
        if ($len - ($endPos - $beginPos) + \strlen($intStr) > $maxLen) {
            return null;
        }

        return \substr($str, 0, $beginPos)
            . $intStr
            . \substr($str, $endPos);
    }

    public function mutateChangeBinInt(string $str, int $maxLen): ?string {
        $len = \strlen($str);
        if ($len > $maxLen) {
            return null;
        }

        $packCodes = [
            'C' => 1,
            'n' => 2, 'v' => 2,
            'N' => 4, 'V' => 4,
            'J' => 8, 'P' => 8,
        ];
        $packCode = $this->rng->randomElement(array_keys($packCodes));
        $numBytes = $packCodes[$packCode];
        if ($numBytes > $len) {
            return null;
        }

        $pos = $this->rng->randomInt($len - $numBytes + 1);
        if ($pos < 64 && $this->rng->randomInt(4) == 0) {
            $int = $len;
        } else {
            $int = \unpack($packCode, $str, $pos)[1];
            $add = $this->rng->randomIntRange(-10, 10);
            $int += $add;
            if ($add == 0 && $this->rng->randomBool()) {
                $int = -$int;
            }
        }
        return \substr($str, 0, $pos)
             . \pack($packCode, $int)
             . \substr($str, $pos + $numBytes);
    }

    private function copyPartOf(string $from, string $to): string {
        $toLen = \strlen($to);
        $fromLen = \strlen($from);
        $toBeg = $this->rng->randomPos($to);
        $numBytes = $this->rng->randomInt($toLen - $toBeg) + 1;
        $numBytes = \min($numBytes, $fromLen);
        $fromBeg = $this->rng->randomInt($fromLen - $numBytes + 1);
        return \substr($to, 0, $toBeg)
            . \substr($from, $fromBeg, $numBytes)
            . \substr($to, $toBeg + $numBytes);
    }

    private function insertPartOf(string $from, string $to, int $maxLen): ?string {
        $toLen = \strlen($to);
        if ($toLen >= $maxLen) {
            return null;
        }

        $fromLen = \strlen($from);
        $maxNumBytes = min($maxLen - $toLen, $fromLen);
        $numBytes = $this->rng->randomInt($maxNumBytes) + 1;
        $fromBeg = $this->rng->randomInt($fromLen - $numBytes + 1);
        $toInsertPos = $this->rng->randomPosOrEnd($to);
        return \substr($to, 0, $toInsertPos)
            . \substr($from, $fromBeg, $numBytes)
            . \substr($to, $toInsertPos);
    }

    private function crossOver(string $str1, string $str2, int $maxLen): string {
        $maxLen = $this->rng->randomInt($maxLen) + 1;
        $len1 = \strlen($str1);
        $len2 = \strlen($str2);
        $pos1 = 0;
        $pos2 = 0;
        $result = '';
        $usingStr1 = true;
        while (\strlen($result) < $maxLen && ($pos1 < $len1 || $pos2 < $len2)) {
            $maxLenLeft = $maxLen - \strlen($result);
            if ($usingStr1) {
                if ($pos1 < $len1) {
                    $maxExtraLen = min($len1 - $pos1, $maxLenLeft);
                    $extraLen = $this->rng->randomInt($maxExtraLen) + 1;
                    $result .= \substr($str1, $pos1, $extraLen);
                    $pos1 += $extraLen;
                }
            } else {
                if ($pos2 < $len2) {
                    $maxExtraLen = min($len2 - $pos2, $maxLenLeft);
                    $extraLen = $this->rng->randomInt($maxExtraLen) + 1;
                    $result .= \substr($str2, $pos2, $extraLen);
                    $pos2 += $extraLen;
                }
            }
            $usingStr1 = !$usingStr1;
        }
        return $result;
    }

    public function mutateCopyPart(string $str, int $maxLen): ?string {
        $len = \strlen($str);
        if ($str === '' || $len > $maxLen) {
            return null;
        }
        if ($len == $maxLen || $this->rng->randomBool()) {
            return $this->copyPartOf($str, $str);
        } else {
            return $this->insertPartOf($str, $str, $maxLen);
        }
    }

    public function mutateCrossOver(string $str, int $maxLen): ?string {
        if ($this->structuralCrossOver !== null) {
            $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($this->rng->randomInt(PHP_INT_MAX)));
            $new = $this->structuralCrossOver->mutate($str, $randomizer);
            return \strlen($new) <= $maxLen ? $new : \substr($new, 0, $maxLen);
        }
        return $this->mutateCrossOverByteLevel($str, $maxLen);
    }

    public function mutateCrossOverWithDonor(string $str, string $donor, int $maxLen): ?string {
        $saved = $this->crossOverWith;
        $this->crossOverWith = $donor;
        $result = $this->mutateCrossOverByteLevel($str, $maxLen);
        $this->crossOverWith = $saved;
        return $result;
    }

    private function mutateCrossOverByteLevel(string $str, int $maxLen): ?string {
        if ($this->crossOverWith === null) {
            return null;
        }
        $len = \strlen($str);
        if ($len > $maxLen || $len === 0 || \strlen($this->crossOverWith) === 0) {
            return null;
        }
        switch ($this->rng->randomInt(3)) {
            case 0:
                return $this->crossOver($str, $this->crossOverWith, $maxLen);
            case 1:
                if ($len == $maxLen) {
                    return $this->insertPartOf($this->crossOverWith, $str, $maxLen);
                }
                /* fallthrough */
            case 2:
                return $this->copyPartOf($this->crossOverWith, $str);
            default:
                throw new \Error("Cannot happen");
        }
    }

    public function mutateAddWordFromManualDictionary(string $str, int $maxLen): ?string {
        $len = \strlen($str);
        if ($len > $maxLen) {
            return null;
        }
        if ($this->dictionary->isEmpty()) {
            return null;
        }

        $word = $this->rng->randomElement($this->dictionary->dict);
        $wordLen = \strlen($word);
        if ($this->rng->randomBool()) {
            // Insert word.
            if ($len + $wordLen > $maxLen) {
                return null;
            }

            $pos = $this->rng->randomPosOrEnd($str);
            return \substr($str, 0, $pos)
                . $word
                . \substr($str, $pos);
        } else {
            // Overwrite with word.
            if ($wordLen > $len) {
                return null;
            }

            $pos = $this->rng->randomInt($len - $wordLen + 1);
            return \substr($str, 0, $pos)
                . $word
                . \substr($str, $pos + $wordLen);
        }
    }

    public function mutateTypeAware(string $str, int $maxLen): ?string {
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($this->rng->randomInt(PHP_INT_MAX)));
        $new = $this->typeAwareMutator->mutate($str, $randomizer);
        if (\strlen($new) > $maxLen) {
            return null;
        }
        return $new;
    }

    /**
     * @param callable $mutator
     */
    private function applyMutatorWithRange(callable $mutator, string $str, int $maxLen): ?string {
        if ($this->forcedOffset === null || $this->forcedLength === null) {
            return $mutator($str, $maxLen);
        }

        $offset = max(0, $this->forcedOffset);
        $length = max(0, $this->forcedLength);
        if ($offset >= \strlen($str)) {
            return $mutator($str, $maxLen);
        }

        $segment = \substr($str, $offset, $length);
        if ($segment === false || $segment === '') {
            return $mutator($str, $maxLen);
        }

        $mutatedSegment = $mutator($segment, min($maxLen, \strlen($segment) + 256));
        if ($mutatedSegment === null) {
            return $mutator($str, $maxLen);
        }

        $newStr = \substr($str, 0, $offset) . $mutatedSegment . \substr($str, $offset + $length);
        if (\strlen($newStr) > $maxLen) {
            return $mutator($str, $maxLen);
        }
        return $newStr;
    }

    /**
     * @param list<callable> $pool
     */
    public function mutateFromPool(string $str, int $maxLen, ?string $crossOverWith, array $pool): string {
        $this->crossOverWith = $crossOverWith;
        while (true) {
            $mutator = $this->rng->randomElement($pool);
            $newStr = $this->applyMutatorWithRange($mutator, $str, $maxLen);
            if (null !== $newStr) {
                $this->lastMutatorUsed = $mutator[1] === 'mutateTypeAware'
                    ? 'TypeAwareMutator'
                    : substr((string) $mutator[1], 6);
                assert(\strlen($newStr) <= $maxLen, 'Mutator ' . $mutator[1]);
                return $newStr;
            }
        }
    }

    /**
     * @return list<callable>
     */
    public function getPoolByNames(array $names): array {
        $pool = [];
        $nameSet = array_fill_keys($names, true);
        foreach ($this->mutators as $mutator) {
            $name = $mutator[1] === 'mutateTypeAware'
                ? 'TypeAwareMutator'
                : substr((string) $mutator[1], 6);
            if (isset($nameSet[$name])) {
                $pool[] = $mutator;
            }
        }
        return $pool;
    }

    public function mutate(string $str, int $maxLen, ?string $crossOverWith): string {
        if ($this->forcedMutatorName !== null) {
            $pool = $this->getPoolByNames([$this->forcedMutatorName]);
            if ($pool !== []) {
                return $this->mutateFromPool($str, $maxLen, $crossOverWith, $pool);
            }
        }
        return $this->mutateFromPool($str, $maxLen, $crossOverWith, $this->mutators);
    }
}
