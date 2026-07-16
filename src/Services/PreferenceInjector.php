<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\DeclarativeMemory;

class PreferenceInjector
{
    /**
     * The markdown header for the top-level preferences section.
     */
    private const SECTION_HEADER = "## User Preferences\n\n";

    /**
     * The markdown header for the binding rules subsection.
     */
    private const RULES_HEADER = "### Binding Rules (MUST follow unless I explicitly override in my current message)\n";

    /**
     * The markdown header for the soft preferences subsection.
     */
    private const PREFS_HEADER = "### Preferences (defaults — always yield if I explicitly ask for something different)\n";

    /**
     * Assemble bounded preference guidance for injection into the system prompt.
     *
     * Reads fresh from the database on every call — no caching across invocations.
     *
     * @param string $userId The user ID to load preferences for.
     * @return string|null The formatted preferences section, or null if empty/disabled.
     */
    public function assemble(string $userId): ?string
    {
        $enabled = config('llm-client.preferences_injection.enabled', true);
        if (!$enabled) {
            return null;
        }

        $entries = DeclarativeMemory::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereIn('type', ['preference', 'rule'])
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->get(['id', 'type', 'content', 'updated_at']);

        $rules = $entries->where('type', 'rule')->values();
        $preferences = $entries->where('type', 'preference')->values();

        if ($rules->isEmpty() && $preferences->isEmpty()) {
            return null;
        }

        $maxTokens = (int) config('llm-client.preferences_injection.max_tokens', 500);

        return $this->assembleSection($rules, $preferences, $maxTokens);
    }

    /**
     * Assemble the markdown section with token budget enforcement.
     *
     * The budget bounds the entire block, headers included. Truncation is at
     * whole-entry granularity — a bullet is either fully present or absent.
     *
     * @param \Illuminate\Support\Collection $rules Binding rules (already ordered by recency).
     * @param \Illuminate\Support\Collection $preferences Soft preferences (already ordered by recency).
     * @param int $maxTokens Token budget for the entire assembled block.
     * @return string|null The bounded section, or null if nothing meaningful fits.
     */
    private function assembleSection(
        \Illuminate\Support\Collection $rules,
        \Illuminate\Support\Collection $preferences,
        int $maxTokens
    ): ?string {
        $maxChars = $maxTokens * 4;

        $parts = [self::SECTION_HEADER];
        $usedChars = strlen(self::SECTION_HEADER);

        // Binding rules are hard constraints: always included in full and never
        // partially truncated, even when they alone overflow the budget.
        if ($rules->isNotEmpty()) {
            $rulesSection = self::RULES_HEADER . $this->formatBullets($rules) . "\n";
            $parts[] = $rulesSection;
            $usedChars += strlen($rulesSection);
        }

        // Soft preferences fill whatever budget remains, dropping whole entries
        // lowest-recency-first. Their header only earns its tokens once an entry
        // fits beneath it, so rules that consumed the budget leave no empty
        // section behind.
        $prefsOverhead = strlen(self::PREFS_HEADER) + 1; // header + trailing blank line
        $bullets = [];
        $bulletChars = 0;

        foreach ($preferences as $entry) {
            $bullet = "- {$entry->content}\n";

            if ($usedChars + $prefsOverhead + $bulletChars + strlen($bullet) > $maxChars) {
                break;
            }

            $bullets[] = $bullet;
            $bulletChars += strlen($bullet);
        }

        if ($rules->isEmpty() && $bullets === []) {
            return null;
        }

        if ($bullets !== []) {
            $parts[] = self::PREFS_HEADER;
            foreach ($bullets as $bullet) {
                $parts[] = $bullet;
            }
            $parts[] = "\n";
        }

        return implode('', $parts);
    }

    /**
     * Format entries as markdown bullet list.
     *
     * @param \Illuminate\Support\Collection $entries Collection of DeclarativeMemory entries.
     * @return string Formatted bullet list string.
     */
    private function formatBullets(\Illuminate\Support\Collection $entries): string
    {
        $bullets = '';
        foreach ($entries as $entry) {
            $bullets .= "- {$entry->content}\n";
        }
        return $bullets;
    }
}
