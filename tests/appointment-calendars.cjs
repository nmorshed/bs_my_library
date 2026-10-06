const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
    try {
        const page = await browser.newPage();
        let loads = 0;
        await page.route('https://app.acuityscheduling.com/**', route => { loads++; return route.fulfill({body:'Calendar fixture'}); });
        await page.route('https://embed.acuityscheduling.com/**', route => route.fulfill({contentType:'text/javascript',body:'window.__acuityFrames=window.__acuityFrames||[];document.querySelectorAll(".bsml-calendar iframe").forEach(f=>{if(!window.__acuityFrames.includes(f))window.__acuityFrames.push(f)});'}));
        const html = fs.readFileSync('/private/tmp/bsml-calendar-fixture.html','utf8');
        await page.setContent('<main>' + html + '</main>');
        await page.addScriptTag({path:path.resolve(__dirname,'../assets/appointment-calendars.js')});
        const panels = page.locator('.bsml-calendar');
        assert.equal(await page.locator('details[open], iframe').count(),0);
        await panels.nth(0).locator('summary').click();
        await page.waitForFunction(() => window.__acuityFrames?.length === 1);
        await panels.nth(2).locator('summary').click();
        await page.waitForFunction(() => window.__acuityFrames?.length === 2);
        assert.equal(await page.locator('details[open]').count(),1);
        assert.equal(await panels.nth(2).locator('iframe').getAttribute('allow'),'payment');
        await panels.nth(0).locator('summary').focus();
        await page.keyboard.press('Enter');
        assert.equal(await panels.nth(0).getAttribute('open'),'');
        assert.equal(await page.locator('details[open]').count(),1);
        assert.equal(loads,2,'Previously opened frames are reused');
        await page.keyboard.press('Space');
        assert.equal(await page.locator('details[open]').count(),0);
        await page.evaluate(() => {
            const main=document.querySelector('main');
            main.dispatchEvent(new CustomEvent('bsml:content-unmount',{bubbles:true}));
            main.replaceChildren();
        });
        assert.equal(await page.evaluate(() => window.__acuityFrames.length),0);
        await page.locator('main').evaluate((main,markup)=>{main.innerHTML=markup;},html);
        assert.equal(await page.locator('details[open],iframe').count(),0);
        await page.locator('summary').first().click();
        await page.waitForFunction(() => window.__acuityFrames.length === 1);
        console.log('PASS accordion: closed initially, exclusive opening, keyboard controls, lazy loading, payment permission, frame reuse, cleanup and remount');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
