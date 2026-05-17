import { afterEach, describe, expect, it } from 'vitest'
import { loadAllConfigs } from '../src/config.js'

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

const secondPayload = {
  v: 1,
  site_url: 'https://second.example.org',
  base_url: 'https://second.example.org/wp-json/wp-mcp-gateway/v1',
  username: 'mcp-gateway-service',
  token: 'wxyz 9999 abcd 0000',
  site_name: 'Second Site',
}

describe('loadAllConfigs — WP_MCP_TOKENS mode', () => {
  const savedEnv: Record<string, string | undefined> = {}

  afterEach(() => {
    for (const key of Object.keys(savedEnv)) {
      if (savedEnv[key] === undefined) {
        delete process.env[key]
      } else {
        process.env[key] = savedEnv[key]
      }
    }
    Object.keys(savedEnv).forEach((k) => delete savedEnv[k])
  })

  function setEnv(vars: Record<string, string | undefined>) {
    for (const [key, val] of Object.entries(vars)) {
      savedEnv[key] = process.env[key]
      if (val === undefined) {
        delete process.env[key]
      } else {
        process.env[key] = val
      }
    }
  }

  it('loads a single token from WP_MCP_TOKENS', async () => {
    const token = makeToken(validPayload)
    setEnv({
      WP_MCP_TOKENS: token,
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
    })

    const configs = await loadAllConfigs()

    expect(configs.size).toBe(1)
    expect(configs.has('example.com')).toBe(true)

    const config = configs.get('example.com')!
    expect(config.wpPluginBaseUrl).toBe(validPayload.base_url)
    expect(config.username).toBe(validPayload.username)
    expect(config.appPassword).toBe(validPayload.token)
    expect(config.siteName).toBe(validPayload.site_name)
    expect(config.httpTimeout).toBe(30_000)
  })

  it('loads multiple comma-separated tokens', async () => {
    const token1 = makeToken(validPayload)
    const token2 = makeToken(secondPayload)
    setEnv({
      WP_MCP_TOKENS: `${token1},${token2}`,
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
    })

    const configs = await loadAllConfigs()

    expect(configs.size).toBe(2)
    expect(configs.has('example.com')).toBe(true)
    expect(configs.has('second.example.org')).toBe(true)

    const first = configs.get('example.com')!
    expect(first.siteName).toBe('My Site')

    const second = configs.get('second.example.org')!
    expect(second.siteName).toBe('Second Site')
  })

  it('skips invalid tokens and loads valid ones', async () => {
    const validToken = makeToken(validPayload)
    setEnv({
      WP_MCP_TOKENS: `not_a_token,${validToken},also_bad`,
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
    })

    const configs = await loadAllConfigs()

    expect(configs.size).toBe(1)
    expect(configs.has('example.com')).toBe(true)
  })

  it('throws if WP_MCP_TOKENS is set but no valid tokens exist', async () => {
    setEnv({
      WP_MCP_TOKENS: 'bad_token_1,bad_token_2',
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
    })

    await expect(loadAllConfigs()).rejects.toThrow('no valid tokens')
  })

  it('handles tokens with extra whitespace', async () => {
    const token = makeToken(validPayload)
    setEnv({
      WP_MCP_TOKENS: `  ${token}  ,  `,
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
    })

    const configs = await loadAllConfigs()

    expect(configs.size).toBe(1)
    expect(configs.has('example.com')).toBe(true)
  })

  it('applies env-var allowlists to token-loaded configs', async () => {
    const token = makeToken(validPayload)
    setEnv({
      WP_MCP_TOKENS: token,
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
      WP_ALLOWED_CONTENT_TYPES: 'post,page',
      WP_ALLOWED_TAXONOMIES: 'category',
    })

    const configs = await loadAllConfigs()
    const config = configs.get('example.com')!

    expect(config.allowedContentTypes).toEqual(['post', 'page'])
    expect(config.allowedTaxonomies).toEqual(['category'])
  })

  it('WP_MCP_TOKENS takes priority over WP_MCP_SITE', async () => {
    const token = makeToken(validPayload)
    setEnv({
      WP_MCP_TOKENS: token,
      WP_MCP_SITE: 'other.com',
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
    })

    const configs = await loadAllConfigs()

    // Should use token mode, not WP_MCP_SITE mode
    expect(configs.size).toBe(1)
    expect(configs.has('example.com')).toBe(true)
    expect(configs.has('other.com')).toBe(false)
  })
})
