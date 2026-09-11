# AI Objectives Generation - Gemini Integration

## Setup

### 1. Get Your Gemini API Key

1. Go to [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Sign in with your Google account
3. Click "Get API Key" or "Create API Key"
4. Copy your API key

### 2. Configure the API Key

**Option A: Environment Variable (Recommended)**
```bash
export GEMINI_API_KEY="your-api-key-here"
```

**Option B: Direct Configuration**
Edit `/api/config.php` and replace:
```php
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');
```

With your actual key:
```php
define('GEMINI_API_KEY', 'AIzaSy...');
```

### 3. Test the Endpoint

```bash
curl -X POST http://localhost/api/ai/generate-objectives \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "case_id": 1,
    "prompt": "Generate investigation objectives for this case"
  }'
```

## Endpoint Details

### POST `/api/ai/generate-objectives`

**Request:**
```json
{
  "case_id": 123,
  "prompt": "Optional custom prompt (uses template if omitted)"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "objectives": [
      {
        "objective_title": "Locate and Identify Subject",
        "objective_description": "...",
        "priority": "urgent",
        "estimated_hours": 15.0,
        "due_days": 3
      }
    ],
    "case_id": 123
  }
}
```

## How It Works

1. **Fetch Case Data**: Retrieves complete case data including client, participants, attorney, and vehicles
2. **Build Prompt**: Combines the AI prompt template with actual case data
3. **Call Gemini**: Sends prompt to Google Gemini API
4. **Parse Response**: Extracts JSON objectives from Gemini's response
5. **Return Results**: Returns structured objectives ready to be added to the case

## Prompt Template

The AI uses `/api/ai_objective_generation_prompt.md` as the instruction template. This includes:
- Guidelines for creating professional objectives
- Priority levels and time estimates
- Workflow patterns (locate → surveil → investigate → report)
- Examples from real cases
- Output format requirements

## Frontend Integration

The objectives are automatically displayed in the case creation wizard (Step 3) where users can:
- Edit the AI prompt before generating
- Select which generated objectives to add
- Manually edit objectives after generation
- Add additional objectives manually

## Troubleshooting

### "Gemini API key not configured"
- Make sure you've set the API key in config.php or as an environment variable

### "Failed to generate objectives"
- Check that the case_id is valid
- Verify the Gemini API key is correct
- Check API logs for detailed error messages

### Empty or Invalid Response
- Gemini might return objectives in unexpected format
- The parser will attempt to extract JSON from markdown code blocks
- Check error logs for parsing details

## Cost Considerations

- Gemini API has a free tier with generous limits
- Each objective generation uses ~2000-4000 tokens
- Monitor your usage at [Google AI Studio](https://makersuite.google.com/)

## Security Notes

- API key should be kept secret (use environment variables in production)
- Endpoint requires JWT authentication
- Case data is not stored by Google (stateless API calls)
