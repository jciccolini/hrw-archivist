#!/usr/bin/env python3
import os
import requests 
import glob
import pytesseract
import pandas as pd
from PIL import Image
from urllib.parse import urljoin
from bs4 import BeautifulSoup
from PyPDF2 import PdfFileReader
from pdf2image import convert_from_path

#Set working directory if needed
os.chdir('../..')

#URL to scrape data from coalex site
url = "http://www.osmre.gov/resources/coalex.shtm"

#Put all files in folder. Create folder if there isn't one
folder_location = r'files/original/'
if not os.path.exists(folder_location):os.mkdir(folder_location)
#Get and parse html
response = requests.get(url)
soup= BeautifulSoup(response.text, "html.parser")     
for link in soup.select("a[href$='.pdf']"):
    #Name files in ascending order
    filename = os.path.join(folder_location,link['href'].split('/')[-1])
    with open(filename, 'wb') as f:
        f.write(requests.get(urljoin(url,link['href'])).content)

#List all files in folder
os.chdir('files/original/')
pdfs = glob.glob("coalex_00*.pdf")

#Make holder for page text
final_text = []

#Function to get metadata and OCR from downloaded files
def get_info(path):
    with open(path, 'rb') as f:
        pages = convert_from_path(path, 500) 
        image_counter = 1
        for page in pages: 
            filename = "page_"+str(image_counter)+".jpg"
            page.save(filename, 'JPEG') 
            image_counter = image_counter + 1
        filelimit = image_counter-1
        for i in range(1, filelimit + 1): 
            filename = "page_"+str(i)+".jpg"
            text = str(((pytesseract.image_to_string(Image.open(filename))))) 
            text = text.replace('-\n', '')     
            final_text.append(text)
            finaltext = "\n".join(final_text)
        pdf = PdfFileReader(f)
        info = pdf.getDocumentInfo()
    return([path, finaltext, info.author, info.creator, info.producer, info.subject, info.title])

#Create dataframe hardcoded for metadata selected. Change or generalize to pull any other metadata
df = pd.DataFrame(columns=['filename','text','author','creator','producer','subject','title'])

#Put metadata in dataframe
for pdf in pdfs:
    df.loc[len(df)]=get_info(pdf)


#Write file to path specified
outname = "coalex_import_df.csv"
outdir = "bulk_import"
if not os.path.exists(outdir):
    os.mkdir(outdir)
path = os.path.join(outdir, outname)
pd.DataFrame.to_csv(df, path_or_buf=path, encoding='utf8', sep='\t')
      