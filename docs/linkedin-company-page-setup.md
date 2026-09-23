# Setting up LinkedIn Company Page posting

This guide is for whoever creates the LinkedIn developer app. You do not need
to touch any code. Follow it top to bottom.

**Time needed:** about 30 minutes of your work, then a wait for LinkedIn to
approve. The approval can take several days. Start early.

---

## What this is for

Cerqle can already post to a person's **own LinkedIn profile**. This adds the
ability to post to a **company page**.

LinkedIn treats these as two different things and will not let one app do both
unless that app has been approved for both. That is why we need a second app.

---

## Before you start

You need:

1. A LinkedIn account.
2. To be an **admin of the LinkedIn company page** you want to post to. If you
   are not, ask the page owner to add you first. Nothing below will work
   without this.
3. The **Callback URL** from Cerqle. To find it: log in to Cerqle as a super
   admin, go to **Integrations**, and open the card called **LinkedIn OAuth
   (Company Page)**. The callback URL is shown at the top of that page. Copy it
   exactly — it must match character for character.

---

## Step 1 — Create the app

1. Go to <https://www.linkedin.com/developers/apps> and sign in.
2. Click **Create app**.
3. Fill in the form:
   - **App name** — something like `Cerqle Company Pages`.
   - **LinkedIn Page** — enter your company page. LinkedIn uses this to check
     the app is really yours. This is the step that fails if you are not an
     admin of the page.
   - **App logo** — upload any logo.
4. Tick the legal agreement and click **Create app**.

## Step 2 — Verify the app

LinkedIn shows a **Verify** button next to the company page you entered.

1. Click it. LinkedIn gives you a verification link.
2. Open that link (or send it to a page admin) and approve it.
3. Go back to the app. It should now show as verified.

The app will not work until this is done.

## Step 3 — Ask for the Community Management API

This is the important step, and the one that takes time.

1. Open the **Products** tab in your app.
2. Find **Community Management API** and click **Request access**.
3. Fill in LinkedIn's form. They ask what your app does. Say plainly that it
   lets a business schedule and publish its own posts to its own LinkedIn
   company page from a customer support and social media dashboard.
4. Submit it.

**Now you wait.** LinkedIn reviews this by hand. It is usually a few days. You
will get an email when it is approved.

> Do not continue to Step 5 until this shows as approved. If you add the
> credentials to Cerqle before approval, clients will see an error when they
> try to connect a page.

## Step 4 — Add the callback URL

You can do this while waiting.

1. Open the **Auth** tab in your app.
2. Under **OAuth 2.0 settings**, find **Authorized redirect URLs**.
3. Paste the Callback URL you copied from Cerqle. Save.

If this does not match exactly, LinkedIn shows a `redirect_uri` error when a
client tries to connect.

## Step 5 — Check the permissions

Once Community Management API is approved, open the **Auth** tab again and look
at **OAuth 2.0 scopes**. You should see these two:

- `r_organization_admin` — lets Cerqle see which pages the person manages.
- `w_organization_social` — lets Cerqle post to those pages.

If either is missing, the product was not fully approved. Go back to the
Products tab and check its status.

## Step 6 — Put the keys into Cerqle

1. In your LinkedIn app, open the **Auth** tab and find **Application
   credentials**. You will see a **Client ID** and a **Client Secret**.
2. In Cerqle, log in as a super admin and go to **Integrations**.
3. Open the card **LinkedIn OAuth (Company Page)**.
4. Paste the Client ID and the Client Secret.
5. Turn the integration **on** and save.

Leave the existing **LinkedIn OAuth (Member Profile)** card exactly as it is.
It is a different app and must keep its own keys.

---

## Step 7 — Test it

1. Log in to Cerqle as a normal client user.
2. Go to **Social Media → Connected Accounts**.
3. You should now see two LinkedIn options: **LinkedIn** and
   **LinkedIn Page**.
4. Click **LinkedIn Page** and approve the LinkedIn screen.
5. Every company page you administer should appear as a connected account.
6. Write a short test post to one of them and publish it.
7. Check the post appears on the real LinkedIn page.

---

## If something goes wrong

| What you see | What it means |
| --- | --- |
| "No LinkedIn company pages were found for this account" | The person who approved the LinkedIn screen is not an admin of any page. Add them as an admin on LinkedIn, then try again. This is by far the most common one. |
| "the list of company pages could not be read … Community Management API" | The product is not approved yet, or was approved on a different app than the one whose keys you pasted. |
| A `redirect_uri` error on LinkedIn's own screen | The callback URL in Step 4 does not exactly match the one Cerqle shows. Check for a missing slash or `http` instead of `https`. |
| "OAuth for linkedin_page is not configured" | The integration is saved but switched off, or the Client ID / Secret is blank. |
| The wrong LinkedIn account is used | LinkedIn reuses whoever is already signed in at linkedin.com. Cerqle offers a "Sign out to use another account" link on the connect dialog — use it. |

---

## Notes for later

- **One app can serve both** if you add both the member products (*Sign In with
  LinkedIn using OpenID Connect*, *Share on LinkedIn*) and *Community
  Management API* to it. In that case paste the same Client ID and Secret into
  both Cerqle cards. Cerqle does not care whether they are the same app; it
  only reads whichever card matches the flow being used.
- **Connecting a page does not disconnect a profile.** A workspace can have
  both at once, and they appear as separate destinations in the composer.
- **Tokens expire** after about 60 days. Cerqle refreshes them automatically.
  If a client is ever asked to reconnect, that is normal and safe.
