import { serve } from "https://deno.land/std@0.177.0/http/server.ts"
import { SmtpClient } from "https://deno.land/x/smtp@v0.7.0/mod.ts"

const corsHeaders = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
}

serve(async (req) => {
  if (req.method === 'OPTIONS') {
    return new Response('ok', { headers: corsHeaders })
  }

  try {
    const { email, otp, fullname } = await req.json()

    const client = new SmtpClient()

    await client.connectTLS({
      hostname: "smtp.gmail.com",
      port: 587,
      username: Deno.env.get("SMTP_USERNAME"),
      password: Deno.env.get("SMTP_PASSWORD"),
    })

    const emailTemplate = `
      <!DOCTYPE html>
      <html>
      <head>
          <style>
              body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
              .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
              .header { background: #006341; color: white; padding: 20px; text-align: center; }
              .content { padding: 30px; }
              .otp-code { font-size: 32px; font-weight: bold; color: #006341; text-align: center; letter-spacing: 8px; margin: 20px 0; padding: 15px; background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 5px; }
              .footer { background: #f8f9fa; padding: 15px; text-align: center; color: #6c757d; font-size: 12px; }
          </style>
      </head>
      <body>
          <div class="container">
              <div class="header">
                  <h2>PLP SmartGrade</h2>
                  <p>One-Time Password Verification</p>
              </div>
              <div class="content">
                  <p>Hello <strong>${fullname}</strong>,</p>
                  <p>Your verification code for PLP SmartGrade is:</p>
                  <div class="otp-code">${otp}</div>
                  <p>This code will expire in <strong>10 minutes</strong>.</p>
                  <p><strong>Security Notice:</strong> Never share this code with anyone.</p>
              </div>
              <div class="footer">
                  <p>&copy; ${new Date().getFullYear()} Pamantasan ng Lungsod ng Pasig. All rights reserved.</p>
              </div>
          </div>
      </body>
      </html>
    `

    await client.send({
      from: "PLP SmartGrade <noreply@plpsmartgrade.com>",
      to: email,
      subject: "PLP SmartGrade - OTP Verification Code",
      html: emailTemplate,
    })

    await client.close()

    return new Response(
      JSON.stringify({ success: true, message: "OTP sent successfully" }),
      { 
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        status: 200 
      }
    )

  } catch (error) {
    return new Response(
      JSON.stringify({ success: false, error: error.message }),
      { 
        headers: { ...corsHeaders, 'Content-Type': 'application/json' },
        status: 500 
      }
    )
  }
})