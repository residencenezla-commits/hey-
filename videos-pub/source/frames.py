import asyncio,sys
from playwright.async_api import async_playwright
P=sys.argv[1]; W,H=int(sys.argv[2]),int(sys.argv[3]); mode=sys.argv[4]; out=sys.argv[5]
async def main():
    async with async_playwright() as p:
        b=await p.chromium.launch(executable_path="/opt/pw-browsers/chromium")
        pg=await b.new_page(viewport={"width":W,"height":H})
        errs=[]; pg.on("pageerror",lambda e: errs.append(str(e)))
        await pg.goto("file://"+P+"/pub.html"); await pg.wait_for_timeout(800)
        if mode=="apercu":
            for t in [0.6,2.6,5.5,9,12.8,17.5,21.5]:
                await pg.evaluate(f"render({t})"); await pg.screenshot(path=f"{out}-{t}.png")
        else:
            import os; os.makedirs(out,exist_ok=True)
            n=int(24*30)
            for i in range(n):
                await pg.evaluate(f"render({i/30})"); await pg.screenshot(path=f"{out}/f{i:04d}.jpg",type="jpeg",quality=92)
        print(errs)
        await b.close()
asyncio.run(main())
