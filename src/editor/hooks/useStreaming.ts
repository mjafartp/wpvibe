/**
 * useStreaming — SSE parsing utilities for WPVibe.
 *
 * Provides a pure function for splitting a raw SSE text buffer into
 * parsed `AIStreamChunk` objects, plus a React hook that manages
 * an internal buffer across incremental reads.
 *
 * The parser follows the Server-Sent Events specification:
 *   - Events are separated by double newlines (`\n\n`).
 *   - Only `data:` fields are parsed; other fields (`event:`, `id:`, `retry:`)
 *     are silently ignored for simplicity.
 *   - The sentinel value `data: [DONE]` is translated into a `done` chunk.
 */

import { useCallback, useRef } from 'react';
import type { AIStreamChunk } from '@/editor/types';

// ---------------------------------------------------------------------------
// Pure parser
// ---------------------------------------------------------------------------

export interface ParseResult {
  /** Successfully parsed SSE events. */
  events: AIStreamChunk[];
  /** Any trailing data that does not yet form a complete event. */
  remaining: string;
}

/**
 * Parse a raw SSE text buffer into individual `AIStreamChunk` events.
 *
 * The caller is responsible for carrying over `remaining` between successive
 * calls (i.e. prepend it to the next chunk of data from the network).
 *
 * @param buffer - Raw SSE text, potentially containing multiple events.
 * @returns An object with the parsed events and any leftover incomplete data.
 *
 * @example
 * ```ts
 * let carry = '';
 * for await (const raw of readStream()) {
 *   const { events, remaining } = parseSSEEvents(carry + raw);
 *   carry = remaining;
 *   for (const event of events) {
 *     handleEvent(event);
 *   }
 * }
 * ```
 */
export function parseSSEEvents(buffer: string): ParseResult {
  const events: AIStreamChunk[] = [];

  // SSE events are delimited by blank lines (\n\n).
  const parts = buffer.split('\n\n');

  // The last element is either an incomplete event or an empty string if the
  // buffer ended exactly on an event boundary.
  const remaining = parts.pop() ?? '';

  for (const part of parts) {
    const trimmed = part.trim();
    if (!trimmed) continue;

    // Collect all `data:` lines within this event block.
    // Per the SSE spec, a single event can span multiple `data:` lines
    // which should be joined with newlines.
    const dataLines: string[] = [];

    for (const line of trimmed.split('\n')) {
      if (line.startsWith('data: ')) {
        dataLines.push(line.slice(6));
      } else if (line.startsWith('data:')) {
        dataLines.push(line.slice(5));
      }
      // Ignore `event:`, `id:`, `retry:`, and comment lines (`:...`).
    }

    if (dataLines.length === 0) continue;

    const dataStr = dataLines.join('\n');

    // Handle the conventional `[DONE]` sentinel.
    if (dataStr.trim() === '[DONE]') {
      events.push({ type: 'done' });
      continue;
    }

    try {
      const chunk = JSON.parse(dataStr) as AIStreamChunk;
      events.push(chunk);
    } catch {
      // Malformed JSON -- skip silently. The stream may include comments
      // or non-JSON data lines that we do not need to surface.
    }
  }

  return { events, remaining };
}

// ---------------------------------------------------------------------------
// React hook
// ---------------------------------------------------------------------------

/**
 * React hook wrapping `parseSSEEvents` with a persistent buffer.
 *
 * Returns a `feed` function that accepts raw text from a `ReadableStream`
 * read and returns any fully-parsed SSE events. The internal buffer carries
 * over incomplete data between calls automatically.
 *
 * Call `reset` when starting a new stream to clear the buffer.
 *
 * @example
 * ```tsx
 * const { feed, reset } = useSSEParser();
 *
 * async function readStream(reader: ReadableStreamDefaultReader<Uint8Array>) {
 *   reset();
 *   const decoder = new TextDecoder();
 *   while (true) {
 *     const { done, value } = await reader.read();
 *     if (done) break;
 *     const events = feed(decoder.decode(value, { stream: true }));
 *     for (const event of events) {
 *       // handle event
 *     }
 *   }
 * }
 * ```
 */
export function useSSEParser() {
  const bufferRef = useRef('');

  const feed = useCallback((rawText: string): AIStreamChunk[] => {
    const { events, remaining } = parseSSEEvents(bufferRef.current + rawText);
    bufferRef.current = remaining;
    return events;
  }, []);

  const reset = useCallback(() => {
    bufferRef.current = '';
  }, []);

  return { feed, reset };
}
