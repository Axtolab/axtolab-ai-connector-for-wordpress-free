import { describe, expect, it } from 'vitest'
import { decodeConnectionToken, isConnectionToken } from '../src/tokenDecoder.js'

/** Helper: build a valid wmcp1_ token from a payload object. */
function makeToken(payload: Record<string, unknown>): string {
  const json = JSON.stringify(payload)
  const b64 = Buffer.from(json, 'utf-8').toString('base64')
  return `wmcp1_${b64}`
}

const validPayload = {
  v: 1,
  site_url: 'https://example.com',
  base_url: 'https://example.com/wp-json/wp-mcp-gateway/v1',
  username: 'mcp-gateway-service',
  token: 'abcd 1234 efgh 5678',
  site_name: 'My Site',
}

// ---------------------------------------------------------------------------
// isConnectionToken
// ---------------------------------------------------------------------------

describe('isConnectionToken', () => {
  it('returns true for a valid wmcp1_ prefix', () => {
    expect(isConnectionToken('wmcp1_abc123')).toBe(true)
  })

  it('returns false for empty string', () => {
    expect(isConnectionToken('')).toBe(false)
  })

  it('returns false for wrong prefix', () => {
    expect(isConnectionToken('wmcp2_abc123')).toBe(false)
  })

  it('returns false for non-string input', () => {
    expect(isConnectionToken(undefined as unknown as string)).toBe(false)
    expect(isConnectionToken(null as unknown as string)).toBe(false)
    expect(isConnectionToken(42 as unknown as string)).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// decodeConnectionToken — happy path
// ---------------------------------------------------------------------------

describe('decodeConnectionToken — valid token', () => {
  it('decodes a well-formed token', () => {
    const token = makeToken(validPayload)
    const result = decodeConnectionToken(token)

    expect(result.v).toBe(1)
    expect(result.site_url).toBe('https://example.com')
    expect(result.base_url).toBe(
      'https://example.com/wp-json/wp-mcp-gateway/v1'
    )
    expect(result.username).toBe('mcp-gateway-service')
    expect(result.token).toBe('abcd 1234 efgh 5678')
    expect(result.site_name).toBe('My Site')
  })
})

// ---------------------------------------------------------------------------
// decodeConnectionToken — error cases
// ---------------------------------------------------------------------------

describe('decodeConnectionToken — errors', () => {
  it('rejects empty string', () => {
    expect(() => decodeConnectionToken('')).toThrow('must start with')
  })

  it('rejects wrong prefix', () => {
    expect(() => decodeConnectionToken('wmcp2_abc')).toThrow('must start with')
  })

  it('rejects token with empty payload after prefix', () => {
    expect(() => decodeConnectionToken('wmcp1_')).toThrow('empty payload')
  })

  it('rejects corrupted base64', () => {
    // Valid base64 but not valid JSON
    const badB64 = Buffer.from('not json at all').toString('base64')
    expect(() => decodeConnectionToken(`wmcp1_${badB64}`)).toThrow(
      'not valid JSON'
    )
  })

  it('rejects non-object JSON (array)', () => {
    const b64 = Buffer.from('[]').toString('base64')
    expect(() => decodeConnectionToken(`wmcp1_${b64}`)).toThrow(
      'must be a JSON object'
    )
  })

  it('rejects non-object JSON (string)', () => {
    const b64 = Buffer.from('"hello"').toString('base64')
    expect(() => decodeConnectionToken(`wmcp1_${b64}`)).toThrow(
      'must be a JSON object'
    )
  })

  it('rejects missing required string fields', () => {
    for (const field of [
      'site_url',
      'base_url',
      'username',
      'token',
      'site_name',
    ]) {
      const payload = { ...validPayload, [field]: undefined }
      expect(() => decodeConnectionToken(makeToken(payload))).toThrow(
        `missing or empty field "${field}"`
      )
    }
  })

  it('rejects empty string fields', () => {
    const payload = { ...validPayload, token: '   ' }
    expect(() => decodeConnectionToken(makeToken(payload))).toThrow(
      'missing or empty field "token"'
    )
  })

  it('rejects missing version', () => {
    const { v, ...rest } = validPayload
    expect(() => decodeConnectionToken(makeToken(rest))).toThrow(
      'missing or invalid version'
    )
  })

  it('rejects unsupported version', () => {
    const payload = { ...validPayload, v: 99 }
    expect(() => decodeConnectionToken(makeToken(payload))).toThrow(
      'Unsupported token version 99'
    )
  })

  it('rejects invalid site_url', () => {
    const payload = { ...validPayload, site_url: 'not-a-url' }
    expect(() => decodeConnectionToken(makeToken(payload))).toThrow(
      '"site_url" is not a valid URL'
    )
  })

  it('rejects invalid base_url', () => {
    const payload = { ...validPayload, base_url: 'also-not-a-url' }
    expect(() => decodeConnectionToken(makeToken(payload))).toThrow(
      '"base_url" is not a valid URL'
    )
  })
})
